#!/usr/bin/env python3
"""
Tests de sécurité défensifs (non destructifs pour une base de test).

Usage :  python3 tests/securite_test.py http://127.0.0.1:8080
Pré-requis : base de test avec les comptes admin / arb1 / arb2 / arb3
(mot de passe « test123 », sauf le compte système admin : « Super »), les combats 1 (en cours, arb1-3), 3 (à venir,
arb1 seul). NE PAS lancer sur la base de production : crée des comptes et
déclenche des verrouillages de connexion.
"""
import re
import sys
import requests

B = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8098').rstrip('/') + '/'
MDP = 'test123'
resultats = []


def ok(nom, cond, detail=''):
    resultats.append((nom, bool(cond), detail))
    print(('OK   ' if cond else 'ÉCHEC') + ' ' + nom + (('  [' + detail + ']') if detail and not cond else ''))


def csrf_de(s, url):
    r = s.get(B + url)
    m = re.search(r'name="csrf" value="([^"]+)"', r.text) or re.search(r'data-csrf="([^"]+)"', r.text)
    return m.group(1) if m else ''


def connexion(user, mdp=MDP):
    s = requests.Session()
    t = csrf_de(s, 'login.php')
    s.post(B + 'login.php', data={'csrf': t, 'username': user, 'password': mdp}, allow_redirects=False)
    return s


# ---------------------------------------------------------------- en-têtes
r = requests.get(B + 'index.php')
h = r.headers
ok('CSP présente avec nonce', "script-src 'self' 'nonce-" in h.get('Content-Security-Policy', ''))
ok("CSP interdit l'intégration en iframe", "frame-ancestors 'none'" in h.get('Content-Security-Policy', ''))
ok('X-Content-Type-Options nosniff', h.get('X-Content-Type-Options') == 'nosniff')
ok('X-Frame-Options DENY', h.get('X-Frame-Options') == 'DENY')
ok('Referrer-Policy', h.get('Referrer-Policy') == 'same-origin')
ok('Pas de X-Powered-By', 'X-Powered-By' not in h)
ok('Pas de version de serveur', re.search(r'\d', h.get('Server', '')) is None, h.get('Server', ''))
ok('Pas de CORS ouvert', 'Access-Control-Allow-Origin' not in h)
ok('Pas de HSTS en HTTP simple', 'Strict-Transport-Security' not in h)
r = requests.get(B + 'index.php', headers={'X-Forwarded-Proto': 'https'})
ok('X-Forwarded-Proto ignoré si le client n\'est pas un proxy de confiance', 'Strict-Transport-Security' not in r.headers)

# ---------------------------------------------------------------- cookie / session
s = requests.Session()
r = s.get(B + 'login.php')
ck = r.headers.get('Set-Cookie', '')
ok('Cookie de session HttpOnly', 'httponly' in ck.lower(), ck)
ok('Cookie de session SameSite=Lax', 'samesite=lax' in ck.lower(), ck)
avant = s.cookies.get('sabre_sid')
t = csrf_de(s, 'login.php')
s.post(B + 'login.php', data={'csrf': t, 'username': 'arb2', 'password': MDP}, allow_redirects=False)
ok('Nouvel identifiant de session après connexion (anti fixation)', s.cookies.get('sabre_sid') and s.cookies.get('sabre_sid') != avant)
r = requests.get(B + 'login.php', headers={'Cookie': 'sabre_sid=identifiantchoisiparlattaquant1234567890abcdefgh'})
nouveau = re.search(r'sabre_sid=([^;]+)', r.headers.get('Set-Cookie', ''))
ok('Identifiant de session imposé refusé (use_strict_mode)', nouveau and nouveau.group(1) != 'identifiantchoisiparlattaquant1234567890abcdefgh')

# ---------------------------------------------------------------- accès sans authentification
anon = requests.Session()
for u in ['admin/index.php', 'admin/users.php', 'admin/roles.php', 'admin/combats.php', 'admin/sabres.php', 'admin/parametres.php', 'arbitrage/index.php', 'arbitrage/saisie.php?combat_id=1', 'compte.php']:
    r = anon.get(B + u, allow_redirects=False)
    ok('Anonyme refusé : ' + u, r.status_code in (302, 401, 403) and 'login.php' in r.headers.get('Location', 'login.php'), str(r.status_code))
r = anon.get(B + 'api/ble_sabre_status.php')
ok('API sabres (MAC) refusée aux anonymes', r.status_code in (401, 403), str(r.status_code))
r = anon.post(B + 'api/points.php', data={'combat_id': 1, 'action': 'ajouter', 'joueur': 1, 'valeur': 3})
ok('Anonyme ne peut pas donner de points', r.status_code == 403, str(r.status_code))
r = anon.get(B + 'admin/sabres.php?ajax=list', allow_redirects=False)
ok('Liste des sabres (AJAX) refusée aux anonymes', r.status_code in (302, 401, 403), str(r.status_code))

# ---------------------------------------------------------------- mauvais rôle / IDOR
arb2 = connexion('arb2')
for u in ['admin/index.php', 'admin/users.php', 'admin/roles.php', 'admin/combats.php', 'admin/parametres.php']:
    r = arb2.get(B + u, allow_redirects=False)
    ok('Arbitre refusé : ' + u, r.status_code == 403, str(r.status_code))
r = arb2.get(B + 'arbitrage/saisie.php?combat_id=3')
ok('Arbitre ne voit pas la saisie d\'un combat où il n\'est pas affecté', r.status_code == 403, str(r.status_code))
t = csrf_de(arb2, 'arbitrage/saisie.php?combat_id=1')
r = arb2.post(B + 'api/points.php', data={'csrf': t, 'combat_id': 3, 'action': 'ajouter', 'joueur': 1, 'valeur': 3})
ok('Arbitre ne peut pas noter un combat où il n\'est pas affecté', r.json().get('ok') is False)
r = arb2.post(B + 'api/points.php', data={'csrf': t, 'combat_id': 1, 'action': 'ajouter', 'joueur': 1, 'valeur': 3})
ok('Arbitre affecté peut noter son combat', r.json().get('ok') is True, r.text[:120])
r = arb2.post(B + 'admin/users.php', data={'csrf': t, 'form_action': 'create', 'username': 'pirate', 'password': 'Tr3sLongMdp!', 'role': 'admin'}, allow_redirects=False)
ok('Arbitre ne peut pas créer de compte (POST direct)', r.status_code in (302, 403) and 'pirate' not in requests.get(B + 'index.php').text)
r = arb2.post(B + 'admin/roles.php', data={'csrf': t, 'form_action': 'update', 'slug': 'arbitre', 'nom': 'Arbitre', 'permissions[]': ['roles.gerer', 'users.modifier']}, allow_redirects=False)
ok('Arbitre ne peut pas modifier les permissions (POST direct)', r.status_code in (302, 403))

# ---------------------------------------------------------------- CSRF
r = arb2.post(B + 'api/points.php', data={'combat_id': 1, 'action': 'ajouter', 'joueur': 1, 'valeur': 3})
ok('POST sans jeton CSRF refusé (API)', r.status_code == 400 and r.json().get('ok') is False)
admin = connexion('admin', 'Super')
r = admin.post(B + 'admin/combats.php', data={'form_action': 'lot', 'lot_action': 'supprimer', 'ids[]': [1]}, allow_redirects=False)
ok('POST sans jeton CSRF refusé (admin)', 'combat.php?id=1' in admin.get(B + 'admin/combats.php?filtre=tous').text)
r = admin.get(B + 'admin/combats.php?action=delete&id=1&csrf=' + csrf_de(admin, 'admin/combats.php'), allow_redirects=False)
ok('Suppression par lien GET impossible', 'combat.php?id=1' in admin.get(B + 'admin/combats.php?filtre=tous').text)

# ---------------------------------------------------------------- entrées invalides / malformées
tA = csrf_de(admin, 'admin/combats.php')
for data in [{'id': 'abc'}, {'id': '-1'}, {'id': '1 OR 1=1'}, {'id[]': '1'}, {'id': '99999999999999999999'}]:
    r = anon.get(B + 'api/get_combat_status.php', params=data)
    ok('ID invalide rejeté proprement : ' + str(data), r.status_code == 400 and 'error' in r.json(), str(r.status_code))
r = arb2.post(B + 'api/points.php', data={'csrf': t, 'combat_id': 1, 'action': 'ajouter', 'joueur': 1, 'valeur': 50})
ok('Valeur de point hors limites refusée', r.json().get('ok') is False)
r = arb2.post(B + 'api/points.php', data={'csrf': t, 'combat_id': 1, 'action': 'ajouter', 'joueur': 3, 'valeur': 1})
ok('Numéro de joueur invalide refusé', r.json().get('ok') is False)
r = arb2.post(B + 'api/points.php', data={'csrf': t, 'combat_id': 1, 'action': 'supprimer_tout'})
ok('Action inconnue refusée', r.status_code == 400)
s3 = requests.Session()
tt = csrf_de(s3, 'login.php')
r = s3.post(B + 'login.php', data={'csrf': tt, 'username[]': 'admin', 'password[]': 'x'})
ok('Tableau à la place d\'une chaîne : pas d\'erreur serveur', r.status_code == 200 and 'Une erreur est survenue' not in r.text)
r = anon.post(B + 'api/points.php', data={'x': 'A' * 5_000_000})
ok('Requête de 5 Mo refusée', r.status_code in (400, 403, 413), str(r.status_code))

# ---------------------------------------------------------------- XSS
xss = '<script>alert(1)</script><img src=x onerror=alert(2)>'
r = admin.post(B + 'admin/joueurs.php', data={'csrf': tA, 'form_action': 'create', 'pseudo': xss, 'nom': 'X'})
page = admin.get(B + 'admin/joueurs.php').text
ok('Pseudo malveillant affiché échappé (joueurs)', '<script>alert(1)</script>' not in page and '&lt;script&gt;' in page)
page = admin.get(B + 'admin/combats.php?action=new').text
ok('Pseudo malveillant échappé dans les listes', '<img src=x onerror' not in page)

# ---------------------------------------------------------------- escalade de privilèges
tR = csrf_de(admin, 'admin/roles.php?action=new')
admin.post(B + 'admin/roles.php', data={'csrf': tR, 'form_action': 'create', 'nom': 'Chef equipe', 'slug': 'chef', 'couleur': '#123456', 'copier_depuis': ''})
admin.post(B + 'admin/roles.php', data={'csrf': tR, 'form_action': 'update', 'slug': 'chef', 'nom': 'Chef equipe', 'couleur': '#123456', 'ordre': 5,
                                        'permissions[]': ['users.voir', 'users.creer', 'users.modifier', 'users.changer_role', 'users.mot_de_passe']})
tU = csrf_de(admin, 'admin/users.php?action=new')
r = admin.post(B + 'admin/users.php', data={'csrf': tU, 'form_action': 'create', 'username': 'chef1', 'password': 'Chef-Mdp-2026!', 'role': 'chef'})
MDP_GENERE = r'Mot de passe : ([a-z0-9-]{14})'
m = re.search(MDP_GENERE, r.text)
ok('Création de compte : mot de passe saisi ignoré, mot de passe généré affiché', bool(m))
import pymysql  # noqa : lecture directe de la base de test
db = pymysql.connect(unix_socket='/run/mysqld/mysqld.sock', user='root', database='sabre_arbitrage', autocommit=True)
chef = connexion('chef1', m.group(1))
tc = csrf_de(chef, 'admin/users.php')
with db.cursor() as c:
    c.execute("SELECT id FROM users WHERE username='chef1'"); chef_id = c.fetchone()[0]
    c.execute("SELECT id FROM users WHERE username='arb3'"); arb3_id = c.fetchone()[0]
    c.execute("SELECT id FROM users WHERE username='admin'"); admin_id = c.fetchone()[0]
chef.post(B + 'admin/users.php', data={'csrf': tc, 'form_action': 'update', 'id': chef_id, 'role': 'admin', 'nom': '', 'prenom': '', 'actif': 'on'})
chef.post(B + 'admin/users.php', data={'csrf': tc, 'form_action': 'update', 'id': arb3_id, 'role': 'admin', 'nom': '', 'prenom': '', 'actif': 'on'})
chef.post(B + 'admin/users.php', data={'csrf': tc, 'form_action': 'create', 'username': 'faux_admin', 'password': 'Faux-Admin-2026!', 'role': 'admin'})
chef.post(B + 'admin/users.php', data={'csrf': tc, 'form_action': 'update', 'id': admin_id, 'role': 'public', 'regenerer_mdp': 'on', 'nom': '', 'prenom': '', 'actif': 'on'})
db.commit()
with db.cursor() as c:
    c.execute("SELECT username, role FROM users WHERE username IN ('chef1','arb3','faux_admin','admin')")
    roles = dict(c.fetchall())
ok('Non-admin ne peut pas se donner le rôle admin', roles.get('chef1') == 'chef', str(roles))
ok('Non-admin ne peut pas promouvoir quelqu\'un admin', roles.get('arb3') == 'arbitre', str(roles))
ok('Non-admin ne peut pas créer un compte admin', roles.get('faux_admin') in (None, 'joueur', 'arbitre', 'public'), str(roles))
ok('Non-admin ne peut pas modifier le compte administrateur', roles.get('admin') == 'admin')
ok('Mot de passe admin inchangé par un non-admin', connexion('admin', 'Super').get(B + 'admin/index.php', allow_redirects=False).status_code == 200)

# ---------------------------------------------------------------- révocation des sessions
autre = connexion('arb3')
ok('Session arb3 valide', autre.get(B + 'arbitrage/index.php', allow_redirects=False).status_code == 200)
tU = csrf_de(admin, 'admin/users.php')
r = admin.post(B + 'admin/users.php', data={'csrf': tU, 'form_action': 'update', 'id': arb3_id, 'role': 'arbitre', 'nom': '', 'prenom': '', 'actif': 'on', 'regenerer_mdp': 'on'})
m = re.search(MDP_GENERE, r.text)
ok('Réinitialisation : nouveau mot de passe généré affiché', bool(m))
r = autre.get(B + 'arbitrage/index.php', allow_redirects=False)
ok('Réinitialisation du mot de passe : anciennes sessions déconnectées', r.status_code == 302, str(r.status_code))
nouvelle = connexion('arb3', m.group(1))
r = nouvelle.get(B + 'arbitrage/index.php', allow_redirects=False)
ok('Mot de passe réinitialisé : utilisable tout de suite', r.status_code == 200, r.headers.get('Location', ''))

# ---------------------------------------------------------------- mot de passe non modifiable, relisible par les admins
tt = csrf_de(nouvelle, 'compte.php')
nouvelle.post(B + 'compte.php', data={'csrf': tt, 'mdp_actuel': m.group(1), 'mdp_nouveau': 'Autre-Mdp-2026!', 'mdp_confirm': 'Autre-Mdp-2026!'})
ok('Mon compte : le mot de passe ne se change pas', connexion('arb3', m.group(1)).get(B + 'arbitrage/index.php', allow_redirects=False).status_code == 200)
ok('Guide : un administrateur voit tous les mots de passe', m.group(1) in admin.get(B + 'aide.php').text)
page = nouvelle.get(B + 'aide.php').text
ok('Guide : un arbitre voit son identifiant, pas les mots de passe', 'arb3' in page and m.group(1) not in page)
for u in ['admin/raspberrys.php']:
    ok('Page Raspberry interdite à un non-admin : ' + u, nouvelle.get(B + u, allow_redirects=False).status_code == 403)
r = requests.get(B + 'api/raspberry_infos.php', headers={'X-Sabre-Jeton': 'faux'})
ok('Infos d\'écran : jeton invalide refusé', r.status_code == 403 and 'mdp' not in r.text)
r = requests.get(B + 'raspberry/infos_ecran.php')
ok('Script interne du Raspberry non servi', r.status_code == 403, str(r.status_code))

# ---------------------------------------------------------------- inscription publique
reg = requests.Session()
tt = csrf_de(reg, 'register.php')
r = reg.post(B + 'register.php', data={'csrf': tt, 'username': 'nouveau1', 'password': 'Joueur-Mdp-2026', 'password_confirm': 'Joueur-Mdp-2026', 'role': 'admin'})
with db.cursor() as c:
    c.execute("SELECT role FROM users WHERE username='nouveau1'"); row = c.fetchone()
ok('Inscription publique : rôle forcé « joueur »', row and row[0] == 'joueur', str(row))
ok('Inscription publique : mot de passe généré affiché', bool(re.search(MDP_GENERE, r.text)))

# ---------------------------------------------------------------- force brute
bf = requests.Session()
for i in range(6):
    tt = csrf_de(bf, 'login.php')
    r = bf.post(B + 'login.php', data={'csrf': tt, 'username': 'arb1', 'password': 'mauvais' + str(i)})
ok('Verrouillage après 5 échecs sur un identifiant', 'Trop de tentatives' in r.text)
tt = csrf_de(bf, 'login.php')
r = bf.post(B + 'login.php', data={'csrf': tt, 'username': 'arb1', 'password': MDP}, allow_redirects=False)
ok('Même le bon mot de passe est refusé pendant le verrouillage', r.status_code == 200 and 'Trop de tentatives' in r.text)
tt = csrf_de(bf, 'login.php')
r = bf.post(B + 'login.php', data={'csrf': tt, 'username': 'inexistant', 'password': 'x'})
ok('Message identique pour un compte inexistant', 'Identifiant ou mot de passe incorrect' in r.text)

# ---------------------------------------------------------------- limitation de débit API
codes = set()
for i in range(620):
    codes.add(anon.get(B + 'api/get_combat_status.php', params={'id': 1}).status_code)
    if 429 in codes:
        break
ok('API publique limitée (429 après ~600 requêtes/min)', 429 in codes, str(codes))

echecs = [n for n, c, _ in resultats if not c]
print('\n%d tests, %d échec(s)' % (len(resultats), len(echecs)))
sys.exit(1 if echecs else 0)
