import board
import time
import digitalio
import displayio
import terminalio
from adafruit_display_text import label
import adafruit_displayio_ssd1306
import i2cdisplaybus

from adafruit_ble import BLERadio
from adafruit_ble.advertising.standard import ProvideServicesAdvertisement
from adafruit_ble.services.nordic import UARTService

# ================= CONFIG =================

# Nom Bluetooth : doit commencer par « TEL » (le Raspberry la reconnaît ainsi)
# et être différent pour chaque télécommande. On lui associe un arbitre dans
# Admin › Sabres › Télécommandes.
DEVICE_NAME = "TEL_2"

PI_TIMEOUT = 3.0       # sans nouvelles du Raspberry depuis 3 s : « PI ? »
DOUBLE_APPUI = 0.15    # rouge + bleu dans ce délai = annuler le dernier point

# Coupe le « BLE workflow » de CircuitPython (transfert de fichiers par Bluetooth) :
# sinon il fait sa propre publicité et gêne la connexion au Raspberry.
try:
    import supervisor
    supervisor.disable_ble_workflow()
except (ImportError, AttributeError, RuntimeError):
    pass

ble = BLERadio()
ble.name = DEVICE_NAME
uart_service = UARTService()
advertisement = ProvideServicesAdvertisement(uart_service)

print(f"{DEVICE_NAME} prêt")

# ================= AFFICHAGE =================

displayio.release_displays()

i2c = board.I2C()
display_bus = i2cdisplaybus.I2CDisplayBus(i2c, device_address=0x3C)
display = adafruit_displayio_ssd1306.SSD1306(display_bus, width=128, height=64)

splash = displayio.Group()
display.root_group = splash

bg = displayio.Bitmap(128, 64, 1)
palette = displayio.Palette(1)
palette[0] = 0x000000
splash.append(displayio.TileGrid(bg, pixel_shader=palette))

# Labels
Info = label.Label(terminalio.FONT, text=DEVICE_NAME, x=0, y=6)
ScoreBleu = label.Label(terminalio.FONT, text="0", x=10, y=30)
ScoreRouge = label.Label(terminalio.FONT, text="0", x=100, y=30)
Temps = label.Label(terminalio.FONT, text="WAIT", x=40, y=50)

splash.append(Info)
splash.append(ScoreBleu)
splash.append(ScoreRouge)
splash.append(Temps)

# ================= LED =================

led_bleue = digitalio.DigitalInOut(board.D9)
led_bleue.direction = digitalio.Direction.OUTPUT

led_rouge = digitalio.DigitalInOut(board.D8)
led_rouge.direction = digitalio.Direction.OUTPUT

# ================= BOUTONS =================

btn_bleu = digitalio.DigitalInOut(board.D10)
btn_bleu.direction = digitalio.Direction.INPUT
btn_bleu.pull = digitalio.Pull.UP

btn_rouge = digitalio.DigitalInOut(board.D7)
btn_rouge.direction = digitalio.Direction.INPUT
btn_rouge.pull = digitalio.Pull.UP

# ================= VARIABLES =================

ETAT = None            # IDLE, FIGHT, STOP, WIN, NOARB
WIN_STATE = None       # R, B ou D
tampon = b""
dernier_msg_pi = 0.0
info_jusqua = 0.0      # message temporaire (OK / erreur) affiché jusqu'à cette heure

# ================= FONCTIONS =================

def envoyer(ligne):
    try:
        uart_service.write((ligne + "\n").encode())
    except Exception as e:
        print("Erreur envoi:", e)


def read_msg():
    """Lignes complètes reçues du Raspberry (un message peut arriver en morceaux)."""
    global tampon
    if not uart_service.in_waiting:
        return []
    try:
        tampon += uart_service.read(uart_service.in_waiting)
    except Exception as e:
        print("Erreur:", e)
        return []
    lignes = []
    while b"\n" in tampon:
        ligne, tampon = tampon.split(b"\n", 1)
        lignes.append(ligne.decode("utf-8").strip())
    if len(tampon) > 256:
        tampon = b""
    return lignes


def afficher_info(texte, duree=1.5):
    global info_jusqua
    Info.text = texte[:21]
    info_jusqua = time.monotonic() + duree


def handle_message(msg):
    global ETAT, WIN_STATE

    print(msg)

    if msg.startswith("TIME:"):
        t = int(msg.split(":")[1])
        Temps.text = f"{t // 60}:{t % 60:02}"

    elif msg.startswith("SCORE:"):
        _, rouge, bleu = msg.split(":")
        ScoreRouge.text = rouge
        ScoreBleu.text = bleu

    elif msg == "FIGHT":
        ETAT = "FIGHT"
        WIN_STATE = None

    elif msg == "STOP":
        ETAT = "STOP"
        Temps.text = "STOP"

    elif msg == "IDLE":
        ETAT = "IDLE"
        WIN_STATE = None
        ScoreBleu.text = "0"
        ScoreRouge.text = "0"
        Temps.text = "ATTENTE"

    elif msg == "NOARB":
        ETAT = "NOARB"
        Temps.text = "SANS ARBITRE"

    elif msg.startswith("WIN:"):
        ETAT = "WIN"
        WIN_STATE = msg.split(":")[1]
        Temps.text = {"R": "ROUGE WIN", "B": "BLEU WIN"}.get(WIN_STATE, "DRAW")

    elif msg == "OK":
        afficher_info("OK", 0.5)

    elif msg.startswith("ERR:"):
        afficher_info(msg[4:])


def clignoter_vainqueur():
    """LED du vainqueur (les deux en cas d'égalité) qui clignote, sans bloquer."""
    allume = int(time.monotonic() / 0.3) % 2 == 0
    led_rouge.value = allume and WIN_STATE in ("R", "D")
    led_bleue.value = allume and WIN_STATE in ("B", "D")


# ================= MAIN =================

while True:
    ble.start_advertising(advertisement)

    while not ble.connected:
        Temps.text = "WAIT"
        led_bleue.value = False
        led_rouge.value = False
        time.sleep(0.2)

    print("Connecté")
    ble.stop_advertising()
    dernier_msg_pi = time.monotonic()
    prev_bleu = False
    prev_rouge = False

    while ble.connected:
        maintenant = time.monotonic()

        for msg in read_msg():
            if msg:
                dernier_msg_pi = maintenant
                handle_message(msg)

        if maintenant - dernier_msg_pi > PI_TIMEOUT:
            Temps.text = "PI ?"
            ETAT = None

        if info_jusqua and maintenant > info_jusqua:
            Info.text = DEVICE_NAME
            info_jusqua = 0.0

        # ===== BOUTONS =====
        pressed_bleu = not btn_bleu.value
        pressed_rouge = not btn_rouge.value

        if (pressed_bleu and not prev_bleu) or (pressed_rouge and not prev_rouge):
            # l'autre bouton suit-il aussitôt ? → les deux ensemble = annuler
            fin = time.monotonic() + DOUBLE_APPUI
            while time.monotonic() < fin and not (pressed_bleu and pressed_rouge):
                pressed_bleu = pressed_bleu or not btn_bleu.value
                pressed_rouge = pressed_rouge or not btn_rouge.value

            if pressed_bleu and pressed_rouge:
                envoyer("UNDO")
                led_bleue.value = True
                led_rouge.value = True
                while not btn_bleu.value or not btn_rouge.value:
                    time.sleep(0.01)   # attendre que les deux soient relâchés
                pressed_bleu = pressed_rouge = False
            elif pressed_rouge:
                envoyer("PT:R")
                led_rouge.value = True
            else:
                envoyer("PT:B")
                led_bleue.value = True
            time.sleep(0.05)   # anti-rebond

        prev_bleu = pressed_bleu
        prev_rouge = pressed_rouge

        # ===== LED =====
        if ETAT == "WIN":
            clignoter_vainqueur()
        else:
            if not pressed_bleu:
                led_bleue.value = False
            if not pressed_rouge:
                led_rouge.value = False

        time.sleep(0.01)
