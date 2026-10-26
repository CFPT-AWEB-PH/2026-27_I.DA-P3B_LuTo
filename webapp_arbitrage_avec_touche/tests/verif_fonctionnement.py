#!/usr/bin/env python3
"""
Vérification complète du fonctionnement de l'application (pages, fichiers,
API, droits d'accès, espaces admin et arbitre).

Sans aucune dépendance (bibliothèque standard de Python uniquement) : se lance
tel quel sur le PC ou sur le Raspberry Pi.

    python3 tests/verif_fonctionnement.py http://10.5.35.17:8080
    python3 tests/verif_fonctionnement.py http://localhost:8080 --admin admin --arbitre arb_lea
    python3 tests/verif_fonctionnement.py http://localhost:8080 --arbitre arb_lea --ecriture

Par défaut : LECTURE SEULE, sans danger sur la base du tournoi.
  --admin X      se connecte avec ce compte et ouvre toutes les pages d'administration
  --arbitre X    se connecte avec ce compte et ouvre l'espace arbitrage et ses pupitres
  --mdp X        mot de passe (sinon demandé au clavier, sans s'afficher)
  --ecriture     donne +1 sur un combat en cours de l'arbitre puis l'annule aussitôt
                 (à réserver à une base de test : le point existe une seconde)

Code de sortie : 0 si tout est bon, 1 s'il y a au moins un échec.
"""
import argparse
import getpass
import http.cookiejar
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# --------------------------------------------------------------------------- affichage
COULEUR = sys.stdout.isatty()
def c(code, txt): return f'\033[{code}m{txt}\033[0m' if COULEUR else txt

resultats = []

def titre(t):
    print('\n' + c('1', '── ' + t + ' ' + '─' * max(0, 60 - len(t))))

def ok(nom, cond, detail=''):
    resultats.append(bool(cond))
    etiquette = c('32', 'OK   ') if cond else c('31', 'ÉCHEC')
    print(f'  {etiquette} {nom}' + (c('90', f'  ({detail})') if detail else ''))
    return bool(cond)

def info(txt):
    print(c('90', '  ·     ' + txt))

def avert(txt):
    print(c('33', '  !     ' + txt))

# --------------------------------------------------------------------------- HTTP
class SansRedirection(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    """Navigateur minimal : cookies de session, redirections optionnelles."""
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.suivre = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.bloquer = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), SansRedirection)

    def req(self, chemin, data=None, suivre=True):
        url = chemin if chemin.startswith('http') else self.base + chemin.lstrip('/')
        corps = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(url, data=corps, headers={'User-Agent': 'verif-fonctionnement/1.0'})
        t0 = time.time()
        try:
            rep = (self.suivre if suivre else self.bloquer).open(r, timeout=20)
            code, entetes, texte, url_finale = rep.status, rep.headers, rep.read(), rep.geturl()
        except urllib.error.HTTPError as e:
            code, entetes, texte, url_finale = e.code, e.headers, e.read(), url
        except Exception as e:  # serveur injoignable, délai dépassé…
            return {'code': 0, 'texte': '', 'brut': b'', 'entetes': {}, 'url': url, 'ms': 0, 'erreur': str(e)}
        return {
            'code': code, 'brut': texte, 'texte': texte.decode('utf-8', 'replace'),
            'entetes': entetes, 'url': url_finale, 'ms': int((time.time() - t0) * 1000), 'erreur': '',
        }

    def json(self, chemin, data=None):
        r = self.req(chemin, data)
        try:
            r['json'] = json.loads(r['texte'])
        except ValueError:
            r['json'] = None
        return r

def erreur_serveur(r):
    """Page d'erreur interne de l'application, ou erreur PHP affichée."""
    t = r['texte']
    return r['code'] >= 500 or 'Erreur interne' in t or re.search(r'(Fatal|Parse) error|Uncaught|<b>Warning</b>', t)

def page_ok(cl, chemin, nom=None, attendu=200):
    r = cl.req(chemin)
    nom = nom or chemin
    if r['erreur']:
        return ok(nom, False, r['erreur']), r
    bon = r['code'] == attendu and not erreur_serveur(r) and (attendu != 200 or '</html>' in r['texte'])
    detail = f"{r['code']} · {r['ms']} ms"
    if r['ms'] > 1500: detail += ' · lent'
    return ok(nom, bon, detail), r

def csrf(texte):
    m = re.search(r'name="csrf" value="([^"]+)"', texte) or re.search(r'data-csrf="([^"]+)"', texte)
    return m.group(1) if m else ''

def connexion(base, user, mdp):
    cl = Client(base)
    r = cl.req('login.php')
    jeton = csrf(r['texte'])
    r = cl.req('login.php', {'csrf': jeton, 'username': user, 'password': mdp}, suivre=False)
    lieu = r['entetes'].get('Location', '') if r['entetes'] else ''
    reussi = r['code'] in (301, 302, 303) and 'login.php' not in lieu
    return cl, reussi, lieu

# --------------------------------------------------------------------------- vérifications
def verifier(args):
    # « localhost » : Windows essaie d'abord IPv6 et perd 2 s par requête (fausse lenteur)
    base = re.sub(r'^(https?://)localhost\b', r'\g<1>127.0.0.1', args.url.rstrip('/') + '/')
    anon = Client(base)
    print(c('1', f'Vérification de {base}'))

    # 1. Le serveur répond ------------------------------------------------------
    titre('1. Serveur')
    r = anon.req('index.php')
    if not ok('Le site répond', r['code'] == 200, r['erreur'] or f"{r['code']} · {r['ms']} ms"):
        avert("Le serveur ne répond pas : vérifiez l'adresse, que Docker tourne (docker compose ps) et le pare-feu.")
        return
    ok('Pas d\'erreur PHP sur l\'accueil', not erreur_serveur(r))
    ok('Base de données joignable', 'Erreur interne' not in r['texte'])

    # 2. Pages publiques ------------------------------------------------------
    titre('2. Pages publiques')
    pages = {
        'index.php': 'Direct', 'arbre.php': 'Arbre', 'scores.php': 'Résultats',
        'classement.php': 'Classement', 'ecran.php': 'Écran de salle',
        'login.php': 'Connexion', 'register.php': 'Inscription',
    }
    textes = {}
    for chemin, nom in pages.items():
        _, rp = page_ok(anon, chemin, f'{nom} ({chemin})')
        textes[chemin] = rp['texte']
    page_ok(anon, 'combat.php?id=999999999', 'Combat inexistant → page 404 propre', attendu=404)

    # 3. Fichiers statiques -----------------------------------------------------
    titre('3. Fichiers (CSS, JS, polices, application installable)')
    accueil = textes.get('index.php', '')
    fichiers = sorted(set(re.findall(r'(?:href|src)="([^"]*assets/[^"]+\.(?:css|js)\?v=[^"]+)"', accueil)))
    ok('Le CSS et le JS sont versionnés (?v=)', bool(fichiers), ', '.join(f.split('/')[-1] for f in fichiers))
    versions = {re.search(r'\?v=([^"&]+)', f).group(1) for f in fichiers}
    ok('Une seule version pour tous les fichiers', len(versions) == 1, ', '.join(sorted(versions)))
    for f in fichiers:
        rf = anon.req(f.replace('&amp;', '&'))
        ok(f.split('/')[-1].split('?')[0], rf['code'] == 200 and len(rf['brut']) > 200, f"{rf['code']} · {len(rf['brut']) // 1024} Ko")
    for police in re.findall(r'href="([^"]+\.woff2)"', accueil):
        rf = anon.req(police)
        ok('Police ' + police.split('/')[-1], rf['code'] == 200)
    sw = anon.req('sw.js')
    ok('Service Worker (sw.js)', sw['code'] == 200 and 'PRECACHE' in sw['texte'])
    if versions and sw['code'] == 200:
        v = next(iter(versions))
        ok('sw.js précharge la même version que les pages', f'?v={v}' in sw['texte'],
           f'pages v={v} · à aligner dans sw.js' if f'?v={v}' not in sw['texte'] else f'v={v}')
    lien_manifeste = re.search(r'rel="manifest" href="([^"]+)"', accueil)
    if ok('Manifeste de l\'app déclaré', bool(lien_manifeste)):
        rm = anon.json(lien_manifeste.group(1).replace('&amp;', '&'))
        js = rm.get('json') or {}
        ok('Manifeste valide (nom, icônes)', bool(js.get('name') and js.get('icons')), js.get('name', ''))
    ok('Page « hors connexion »', anon.req('assets/pwa/offline.html')['code'] == 200)

    # 4. Combats et API en direct ---------------------------------------------------
    titre('4. Combats et API en direct')
    ids = set()
    for t in textes.values():
        ids |= set(re.findall(r'data-combat-id="(\d+)"', t))
        ids |= set(re.findall(r'combat\.php\?id=(\d+)', t))
    ids = sorted(ids, key=int)[:args.max_combats]
    if not ids:
        info('Aucun combat publié : les vérifications de combats sont sautées (normal avant le tournoi).')
    statuts = {}
    for i in ids:
        ra = anon.json(f'api/get_combat_status.php?id={i}')
        d = ra.get('json') or {}
        statuts[i] = d.get('statut')
        cles = {'statut', 'score_joueur1', 'score_joueur2', 'temps_restant', 'avantage', 'server_now_ms'}
        bon = ra['code'] == 200 and cles <= set(d)
        detail = f"{d.get('statut', '?')} · {d.get('score_joueur1', '?')}-{d.get('score_joueur2', '?')}"
        if d.get('statut') == 'en_cours':
            detail += f" · reste {d.get('temps_restant')} s" + (' · EN PAUSE' if d.get('en_pause') else '')
        ok(f'Combat {i} : API statut', bon, detail)
        page_ok(anon, f'combat.php?id={i}', f'Combat {i} : page publique')
        rt = anon.json(f'api/ble_touches.php?combat_id={i}&historique=1')
        t = rt.get('json') or {}
        ok(f'Combat {i} : API touches', rt['code'] == 200 and 'compte' in t,
           f"{t.get('total', 0)} touche(s)" if t else str(rt['code']))
        rpts = anon.json(f'api/points.php?combat_id={i}')
        ok(f'Combat {i} : API points', rpts['code'] == 200 and rpts.get('json') is not None)
        if d.get('statut') == 'en_cours':
            ok(f'Combat {i} : chrono cohérent', 0 <= int(d.get('temps_restant', -1)) <= 3600, f"{d.get('temps_restant')} s")
    r400 = anon.json('api/get_combat_status.php')
    ok('API : identifiant manquant refusé proprement (400)', r400['code'] == 400)
    if ids:
        d = anon.json(f'api/get_combat_status.php?id={ids[0]}').get('json') or {}
        if d.get('server_now_ms'):
            # Pas un échec : l'app se cale sur l'heure du serveur, mais un écart trahit un Pi mal réglé
            ecart = abs(d['server_now_ms'] / 1000 - time.time())
            if ecart < 120:
                info(f'Horloge du serveur alignée sur ce PC (écart {ecart:.0f} s)')
            else:
                avert(f'Horloge du serveur et de ce PC décalées de {ecart / 60:.0f} min : l\'une des deux est fausse. '
                      'Comparez avec l\'heure d\'un téléphone ; si c\'est le serveur, les heures prévues des combats seront fausses.')
    en_cours = [i for i, s in statuts.items() if s == 'en_cours']
    if en_cours:
        ok('Les combats en direct apparaissent sur l\'accueil', all(f'data-combat-id="{i}"' in accueil for i in en_cours))

    # 5. Accès protégés -------------------------------------------------------
    titre('5. Accès protégés (visiteur non connecté)')
    for chemin in ['admin/index.php', 'admin/combats.php', 'admin/users.php', 'arbitrage/index.php', 'compte.php']:
        rp = anon.req(chemin, suivre=False)
        lieu = rp['entetes'].get('Location', '') if rp['entetes'] else ''
        ok(f'{chemin} → connexion demandée', rp['code'] in (302, 303) and 'login.php' in lieu, f"{rp['code']} {lieu}")
    rp = anon.req('api/points.php', {'combat_id': ids[0] if ids else 1, 'action': 'ajouter', 'joueur': 1, 'valeur': 1})
    ok('Un visiteur ne peut pas donner de points', rp['code'] in (403, 404), str(rp['code']))

    # 6. Administration ------------------------------------------------------
    mdp = args.mdp
    def demander_mdp(compte):
        nonlocal mdp
        if mdp is None:
            mdp = getpass.getpass(f'  Mot de passe de {compte} : ')
        return mdp

    if args.admin:
        titre(f'6. Administration (compte {args.admin})')
        cl, reussi, lieu = connexion(base, args.admin, demander_mdp(args.admin))
        if ok('Connexion', reussi, lieu or 'refusée : identifiant ou mot de passe'):
            if 'compte.php' in lieu:
                avert('Ce compte doit d\'abord changer son mot de passe (page Mon compte) : les pages admin sont bloquées.')
            else:
                for f in ['index.php', 'combats.php', 'combats.php?filtre=tous', 'combats.php?action=new',
                          'combats.php?action=generer', 'joueurs.php', 'users.php', 'sabres.php', 'roles.php',
                          'wifi.php', 'bilan.php', 'parametres.php', 'apparence.php']:
                    rp = cl.req('admin/' + f)
                    if 'login.php' in rp['url'] or rp['code'] == 403:
                        info(f'admin/{f} : pas autorisé pour ce rôle (ignoré)')
                        continue
                    ok('admin/' + f, rp['code'] == 200 and not erreur_serveur(rp), f"{rp['code']} · {rp['ms']} ms")
                rs = cl.json('admin/sabres.php?ajax=list')
                if rs['code'] == 200:
                    ok('Liste des sabres en direct (JSON)', rs.get('json') is not None)
                page_ok(cl, 'compte.php', 'Mon compte')
            cl.req('logout.php', {'csrf': csrf(cl.req('index.php')['texte'])})

    # 7. Arbitrage -------------------------------------------------------------
    if args.arbitre:
        titre(f'7. Arbitrage (compte {args.arbitre})')
        cl, reussi, lieu = connexion(base, args.arbitre, demander_mdp(args.arbitre))
        if ok('Connexion', reussi, lieu or 'refusée : identifiant ou mot de passe'):
            if 'compte.php' in lieu:
                avert('Ce compte doit d\'abord changer son mot de passe (page Mon compte).')
            else:
                ok_, rp = page_ok(cl, 'arbitrage/index.php', 'Espace arbitrage')
                pupitres = sorted(set(re.findall(r'saisie\.php\?combat_id=(\d+)', rp['texte'])), key=int)
                if not pupitres:
                    info('Aucun combat confié à cet arbitre pour le moment.')
                actif = None
                for i in pupitres:
                    _, rs = page_ok(cl, f'arbitrage/saisie.php?combat_id={i}', f'Pupitre du combat {i}')
                    if 'data-actif="1"' in rs['texte'] and actif is None:
                        actif = (i, csrf(rs['texte']))
                if actif:
                    info(f'Pupitre actif (boutons +1/+2/+3 utilisables) : combat {actif[0]}')
                elif pupitres:
                    info('Aucun pupitre actif : aucun combat de cet arbitre n\'est en cours.')

                if args.ecriture:
                    if not actif:
                        avert('--ecriture : aucun combat en cours pour cet arbitre, test d\'écriture sauté.')
                    else:
                        i, jeton = actif
                        avant = cl.json(f'api/points.php?combat_id={i}').get('json') or {}
                        m0 = (avant.get('mes') or {}).get('j1')
                        ra = cl.json('api/points.php', {'combat_id': i, 'csrf': jeton, 'action': 'ajouter', 'joueur': 1, 'valeur': 1})
                        m1 = ((ra.get('json') or {}).get('mes') or {}).get('j1')
                        ok('Donner +1 : enregistré', ra['code'] == 200 and (ra.get('json') or {}).get('ok') and m1 == (m0 or 0) + 1,
                           f'mes points Aka {m0} → {m1}')
                        ru = cl.json('api/points.php', {'combat_id': i, 'csrf': jeton, 'action': 'annuler'})
                        m2 = ((ru.get('json') or {}).get('mes') or {}).get('j1')
                        ok('Annuler mon dernier point : le score revient', (ru.get('json') or {}).get('ok') and m2 == m0,
                           f'mes points Aka {m1} → {m2}')
            cl.req('logout.php', {'csrf': csrf(cl.req('index.php')['texte'])})

    if not args.admin and not args.arbitre:
        info('Astuce : ajoutez --admin <compte> et --arbitre <compte> pour vérifier aussi les espaces connectés.')

# --------------------------------------------------------------------------- lancement
def main():
    p = argparse.ArgumentParser(description='Vérifie que toute l\'application fonctionne.')
    p.add_argument('url', nargs='?', default='http://127.0.0.1:8080', help='adresse de l\'app (défaut : http://127.0.0.1:8080)')
    p.add_argument('--admin', help='compte administrateur à tester')
    p.add_argument('--arbitre', help='compte arbitre à tester')
    p.add_argument('--mdp', help='mot de passe (sinon demandé)')
    p.add_argument('--ecriture', action='store_true', help='teste +1 puis annulation sur un combat en cours (base de test)')
    p.add_argument('--max-combats', type=int, default=12, help='nombre de combats vérifiés (défaut 12)')
    args = p.parse_args()

    t0 = time.time()
    try:
        verifier(args)
    except KeyboardInterrupt:
        print('\nInterrompu.')
        return 1
    nb_ok, nb_ko = resultats.count(True), resultats.count(False)
    print('\n' + c('1', '─' * 64))
    if nb_ko == 0:
        print(c('32;1', f'  TOUT FONCTIONNE : {nb_ok} vérifications réussies') + c('90', f' en {time.time() - t0:.1f} s'))
    else:
        print(c('31;1', f'  {nb_ko} PROBLÈME(S)') + f' sur {nb_ok + nb_ko} vérifications. Voir les lignes ÉCHEC ci-dessus.')
        print(c('90', '  Journal du serveur : docker compose logs --tail=50 web'))
    return 0 if nb_ko == 0 else 1

if __name__ == '__main__':
    sys.exit(main())
