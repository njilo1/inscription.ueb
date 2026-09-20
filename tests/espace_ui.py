"""Scénarios navigateur de Mon espace, sur des dossiers et PDF fictifs."""
import base64
import http.server
import json
import mimetypes
import pathlib
import tempfile
import threading
import time
import urllib.parse


def verifier_espace(call, js, change, root):
    theme = pathlib.Path(__file__).resolve().parents[1]
    requests = []
    pdf_failure = [False]

    class Fixture(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            path = urllib.parse.urlparse(self.path).path
            if path.endswith('/pdf'):
                requests.append(path)
                if pdf_failure[0]:
                    self.send_error(503)
                    return
                content = (root / 'ancien-1.pdf').read_bytes()
                content_type = 'application/pdf'
            else:
                base = theme if path.startswith('/theme/') else root
                relative = path.removeprefix('/theme/') if base == theme else path.lstrip('/')
                file = (base / relative).resolve()
                if not file.is_relative_to(base) or not file.is_file():
                    self.send_error(404)
                    return
                content = file.read_bytes()
                content_type = mimetypes.guess_type(str(file))[0] or 'application/octet-stream'
                if file.suffix == '.html':
                    content = content.decode().replace('file://' + str(theme), '/theme').replace('http://localhost/inscription-ueb', '/inscription-ueb').encode()
            self.send_response(200)
            self.send_header('Content-Type', content_type)
            self.send_header('Content-Length', str(len(content)))
            if content_type == 'application/pdf':
                self.send_header('Content-Disposition', 'attachment; filename="quitus-test.pdf"')
            self.end_headers()
            self.wfile.write(content)

    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    origin = 'http://127.0.0.1:' + str(server.server_port)
    # Chromium peut écraser le même nom : isoler les téléchargements de chaque essai.
    download_dir = pathlib.Path(tempfile.mkdtemp(prefix='telechargements-', dir=root))
    call('Browser.setDownloadBehavior', {'behavior': 'allow', 'downloadPath': str(download_dir)})
    call('Emulation.setEmulatedMedia', {'features': []})

    def load(name, width=1280):
        call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False})
        url = origin + '/' + name + '.html'
        call('Page.navigate', {'url': url})
        for _ in range(100):
            time.sleep(.05)
            if js('location.href === ' + json.dumps(url) + ' && document.readyState === "complete"'):
                break
        js('document.fonts.ready')

    def screenshot(name, selector=None):
        params = {'format': 'png', 'captureBeyondViewport': bool(selector)}
        if selector:
            js('document.querySelector(' + json.dumps(selector) + ').scrollIntoView({block:"start",behavior:"instant"})')
            js('new Promise(r=>setTimeout(r,400))')
            params['clip'] = js('(()=>{const r=document.querySelector(' + json.dumps(selector) + ').getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()')
        shot = call('Page.captureScreenshot', params)
        (root / (name + '.png')).write_bytes(base64.b64decode(shot['data']))

    try:
        for case, cards in [('vide', 0), ('ancien', 1), ('nouveau', 1), ('deuxieme', 2), ('mixte', 1), ('rejete', 1), ('verifie', 1), ('medical-seul', 1)]:
            load('espace-' + case)
            change('#mes-quitus > summary')
            assert js('document.querySelectorAll("#mes-quitus [data-dossier]").length') == cards, case
            assert js('document.querySelectorAll("[data-dossier-pdf]").length') == cards
            if case in ('mixte', 'rejete', 'verifie'):
                assert js('document.querySelectorAll("[data-dossier-modifier]").length') == 0
            if case == 'ancien':
                assert js('document.querySelectorAll("[data-dossier] .btn").length') == 3
                assert 'Master 1' in js('document.querySelector("[data-dossier]").textContent')
                assert '28 000' in js('document.querySelector(".dossier-quitus__total").textContent')
            if case == 'rejete':
                assert 'illisible' in js('document.querySelector(".dossier-quitus__motif").textContent')
            if case == 'nouveau':
                assert '1 page' in js('document.querySelector("[data-dossier-pdf]").textContent')

        for width in [1280, 768, 375, 320]:
            load('espace-archives', width)
            change('#mes-quitus > summary')
            assert js('document.documentElement.scrollWidth <= innerWidth'), ('débordement espace', width)
            assert js('document.querySelector(".dossier-quitus__entete > div").getBoundingClientRect().width > 125'), ('en-tête trop serré', width)
            assert js('document.querySelectorAll(".quitus-annee").length') == 2
            assert not js(r'document.querySelector("[data-annee=\"2020-2021\"]").open')
            change('[data-annee="2020-2021"] > summary')
            assert js(r'document.querySelector("[data-annee=\"2020-2021\"]").open')
            assert not js(r'document.querySelector("[data-annee=\"2020-2021\"] [data-dossier-modifier]")')
            assert js('document.documentElement.scrollWidth <= innerWidth')
            screenshot('espace-archives-' + str(width), '#mes-quitus')
            # Fermer les archives au clavier.
            js(r'document.querySelector("[data-annee=\"2020-2021\"] > summary").focus()')
            call('Input.dispatchKeyEvent', {'type': 'keyDown', 'key': 'Enter', 'code': 'Enter', 'windowsVirtualKeyCode': 13, 'text': '\r'})
            call('Input.dispatchKeyEvent', {'type': 'keyUp', 'key': 'Enter', 'code': 'Enter', 'windowsVirtualKeyCode': 13})
            js('new Promise(r=>setTimeout(r,100))')
            assert not js(r'document.querySelector("[data-annee=\"2020-2021\"]").open')

        load('espace-nouvelle-annee')
        assert 'Une nouvelle année commence' in js('document.querySelector(".mes-quitus__vide").textContent')
        assert 'Prépare ton inscription' in js('document.querySelector("#etape-titre").textContent')

        for case in ['droits', 'medicaux']:
            load('recus-' + case, 375)
            assert js('document.querySelectorAll(".recus-paiements a").length') == 2
            assert js('document.querySelectorAll(".recus-paiements [aria-current=page]").length') == 1
            assert js('document.documentElement.scrollWidth <= innerWidth')

        load('espace-bienvenue', 375)
        assert js('document.querySelector("[data-bienvenue]").matches(":modal")')
        assert js('document.querySelector("[data-bienvenue]").contains(document.activeElement)')
        screenshot('espace-bienvenue-375')
        call('Input.dispatchKeyEvent', {'type': 'keyDown', 'key': 'Tab', 'code': 'Tab', 'windowsVirtualKeyCode': 9})
        call('Input.dispatchKeyEvent', {'type': 'keyUp', 'key': 'Tab', 'code': 'Tab', 'windowsVirtualKeyCode': 9})
        # Tab reste dans la boîte native ; Échap et le bouton permettent tous deux de sortir.
        call('Input.dispatchKeyEvent', {'type': 'keyDown', 'key': 'Escape', 'code': 'Escape', 'windowsVirtualKeyCode': 27})
        call('Input.dispatchKeyEvent', {'type': 'keyUp', 'key': 'Escape', 'code': 'Escape', 'windowsVirtualKeyCode': 27})
        assert not js('document.querySelector("[data-bienvenue]").open')
        load('espace-bienvenue')
        change('[data-bienvenue] button')
        assert not js('document.querySelector("[data-bienvenue]").open')
        load('espace-retour')
        assert not js('document.querySelector("[data-bienvenue]")')
        assert js('getComputedStyle(document.querySelector(".confidentialite__ruban")).animationName') == 'confidentialite-defiler'
        change('[data-pause-confidentialite]')
        assert js('document.querySelector("[data-confidentialite]").classList.contains("est-en-pause")')
        assert js('getComputedStyle(document.querySelector(".confidentialite__ruban")).animationPlayState') == 'paused'
        change('[data-pause-confidentialite]')
        assert not js('document.querySelector("[data-confidentialite]").classList.contains("est-en-pause")')
        call('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-motion', 'value': 'reduce'}]})
        # Le changement CSS précède parfois l'événement matchMedia qui masque le bouton.
        for _ in range(40):
            if js('document.querySelector("[data-pause-confidentialite]").hidden'):
                break
            time.sleep(.025)
        assert js('getComputedStyle(document.querySelector(".confidentialite__ruban")).animationName') == 'none'
        assert js('document.querySelector("[data-pause-confidentialite]").hidden')
        assert js('document.documentElement.scrollWidth <= innerWidth')
        call('Emulation.setEmulatedMedia', {'features': []})

        previous = set(download_dir.glob('*.pdf'))
        load('espace-telechargement')
        for _ in range(100):
            time.sleep(.05)
            if set(download_dir.glob('*.pdf')) - previous:
                break
        saved = set(download_dir.glob('*.pdf')) - previous
        assert saved, 'le clic Générer doit déclencher un vrai fichier téléchargé'
        assert next(iter(saved)).read_bytes() == (root / 'ancien-1.pdf').read_bytes()
        assert len(requests) == 1, requests
        load('espace-apres-telechargement')
        assert len(requests) == 1, 'aucun nouveau téléchargement au retour'
        # Le bouton manuel fonctionne aussi.
        change('[data-dossier-pdf]')
        for _ in range(50):
            if len(requests) == 2:
                break
            time.sleep(.05)
        assert len(requests) == 2
        assert js('location.pathname') == '/espace-apres-telechargement.html'
        pdf_failure[0] = True
        load('espace-telechargement')
        for _ in range(50):
            if 'n’a pas abouti' in js('document.querySelector("[data-telechargement-message]").textContent'):
                break
            time.sleep(.05)
        assert 'n’a pas abouti' in js('document.querySelector("[data-telechargement-message]").textContent')
        assert js('document.querySelector("[data-telechargement-lien]").getClientRects().length > 0')

        call('Emulation.setScriptExecutionDisabled', {'value': True})
        load('espace-archives', 375)
        assert js('document.documentElement.scrollWidth <= innerWidth')
        assert js('getComputedStyle(document.querySelector(".confidentialite__ruban")).animationName') == 'none'
        call('Emulation.setScriptExecutionDisabled', {'value': False})
        print('Mon espace : dossiers groupés, niveaux, années, reçus, popup, clavier, Pause, mouvement réduit, 320–1280 px et téléchargements PDF automatiques/manuels vérifiés.')
    finally:
        call('Emulation.setScriptExecutionDisabled', {'value': False})
        server.shutdown()
        server.server_close()
