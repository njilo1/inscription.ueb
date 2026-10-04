"""Chromium : fiche scolarité sur fixtures PHP, sans requête vers la base.
Générer les aperçus avec tests/quitus-fiche.php avant de lancer ce script.
"""
import base64
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.request
import websocket

ROOT = pathlib.Path('/tmp/ueb-quitus-fiche-review')
with tempfile.TemporaryDirectory(prefix='ueb-quitus-chromium-', ignore_cleanup_errors=True) as profile:
    browser = subprocess.Popen([
        '/usr/bin/chromium', '--headless', '--no-sandbox', '--disable-gpu',
        '--allow-file-access-from-files', '--remote-debugging-port=0',
        '--user-data-dir=' + profile, 'about:blank',
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        port_file = pathlib.Path(profile) / 'DevToolsActivePort'
        for _ in range(100):
            if port_file.exists():
                break
            if browser.poll() is not None:
                raise RuntimeError('Chromium ne peut pas démarrer.')
            time.sleep(.1)
        port = port_file.read_text().splitlines()[0]
        info = json.load(urllib.request.urlopen('http://127.0.0.1:' + port + '/json/version'))
        ws = websocket.create_connection(info['webSocketDebuggerUrl'], suppress_origin=True, timeout=15)
        seq = 0
        session = None
        errors = []

        def call(method, params=None):
            global seq
            seq += 1
            msg = {'id': seq, 'method': method, 'params': params or {}}
            if session:
                msg['sessionId'] = session
            ws.send(json.dumps(msg))
            while True:
                reply = json.loads(ws.recv())
                if reply.get('method') == 'Runtime.exceptionThrown':
                    errors.append(reply['params'])
                if reply.get('id') == seq:
                    assert 'error' not in reply, reply
                    return reply.get('result', {})

        target = call('Target.createTarget', {'url': 'about:blank'})['targetId']
        session = call('Target.attachToTarget', {'targetId': target, 'flatten': True})['sessionId']
        call('Runtime.enable')
        call('Page.enable')

        def js(expression):
            result = call('Runtime.evaluate', {'expression': expression, 'returnByValue': True, 'awaitPromise': True})
            assert 'exceptionDetails' not in result, result
            return result['result'].get('value')

        def load(name, width=1440, motion='no-preference'):
            call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 1100, 'deviceScaleFactor': 1, 'mobile': False})
            call('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-motion', 'value': motion}]})
            url = (ROOT / (name + '.html')).as_uri()
            call('Page.navigate', {'url': url})
            for _ in range(80):
                time.sleep(.05)
                if js('location.href === ' + json.dumps(url) + ' && document.readyState === "complete"'):
                    break
            js('document.fonts.ready')

        def screenshot(name):
            data = call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': False})['data']
            (ROOT / (name + '.png')).write_bytes(base64.b64decode(data))

        def layout(name, width):
            assert js('document.documentElement.scrollWidth <= innerWidth'), (name, width, 'débordement horizontal')
            assert js('document.querySelector("h1").getBoundingClientRect().width > 100'), (name, width, 'identité trop serrée')
            assert js('document.querySelector(".qf-decision").getBoundingClientRect().width > 250'), (name, width, 'décision trop étroite')
            assert js('[...document.querySelectorAll(".qf .adm-bouton")].filter(e=>e.getClientRects().length).every(e=>e.getBoundingClientRect().height >= 44)'), (name, width, 'cible tactile')
            assert js('[...document.querySelectorAll(".qf-recu__apercu img")].filter(e=>e.getClientRects().length).every(e=>e.getBoundingClientRect().bottom <= e.closest(".qf-recu__apercu").getBoundingClientRect().bottom + 1)'), (name, width, 'aperçu déborde')

        for width in [375, 768, 1024, 1440]:
            for name in ['genere', 'recu_envoye', 'verifie', 'rejete', 'lecture', 'medicaux', 'multiple', 'long', 'pdf']:
                load(name, width, 'reduce')
                layout(name, width)
            print('OK : neuf états à', width, 'px', flush=True)

        load('multiple', 1440, 'reduce')
        assert js('document.querySelectorAll("[data-qf-recu]:not([hidden])").length') == 1
        assert js('document.querySelector("[data-qf-recu]:not([hidden])").id') == 'qf-recu-1'
        js('document.querySelectorAll("[data-qf-choix]")[1].focus()')
        call('Input.dispatchKeyEvent', {'type': 'keyDown', 'key': 'Enter', 'code': 'Enter', 'windowsVirtualKeyCode': 13})
        call('Input.dispatchKeyEvent', {'type': 'keyUp', 'key': 'Enter', 'code': 'Enter', 'windowsVirtualKeyCode': 13})
        assert js('document.querySelector("[data-qf-recu]:not([hidden])").id') == 'qf-recu-3'
        assert js('document.querySelector("[data-qf-choix][aria-current]").hash') == '#qf-recu-3'
        assert js('document.querySelector("[data-qf-annonce]").textContent.includes(document.querySelector("[data-qf-recu]:not([hidden]) time").textContent)')
        js('document.querySelectorAll("[data-qf-choix]")[0].click()')
        assert js('document.querySelector("[data-qf-recu]:not([hidden])").id') == 'qf-recu-1'

        assert not js('document.querySelector(".qf-renvoi").open')
        js('document.querySelector(".qf-renvoi summary").click()')
        assert js('document.querySelector(".qf-renvoi").open')
        assert not js('document.querySelector(".qf-rejet").checkValidity()')
        js('document.querySelector("#motif").value="Reçu illisible, merci de transmettre une photo nette."')
        assert js('document.querySelector(".qf-rejet").checkValidity()')
        # Vérifie la charge utile sans envoyer une décision.
        assert js('new FormData(document.querySelector(".qf-rejet")).get("statut")') == 'rejete'
        assert js('new FormData(document.querySelector(".qf-rejet")).get("ueb_csrf")') == 'fixture'
        js('document.querySelector(".qf-renvoi").open=false')
        screenshot('desktop-final')
        print('OK : sélection au clavier, annonce, formulaire de renvoi', flush=True)

        # Le sceau doit être immédiatement complet et rester fixe en mode réduit.
        path = '.qf-sceau svg > path'
        assert js('getComputedStyle(document.querySelector(' + json.dumps(path) + ')).strokeDashoffset') == '0px'
        state = js('document.querySelector(".qf-sceau").innerHTML')
        time.sleep(.2)
        assert state == js('document.querySelector(".qf-sceau").innerHTML')
        load('recu_envoye', 1440)
        time.sleep(2.5)
        assert js('getComputedStyle(document.querySelector(' + json.dumps(path) + ')).strokeDashoffset') == '0px'
        assert js('document.querySelector(".qf-sceau").classList.contains("est-pret")')
        print('OK : Remotion animé et état fixe avec mouvement réduit', flush=True)

        for name, width in [('recu_envoye', 375), ('verifie', 1440), ('rejete', 1440), ('genere', 1440)]:
            load(name, width, 'reduce')
            screenshot(name + '-' + str(width))

        # Sans JS, toutes les pièces et les liens restent utilisables.
        call('Emulation.setScriptExecutionDisabled', {'value': True})
        load('multiple', 1440, 'reduce')
        assert js('document.querySelectorAll("[data-qf-recu]:not([hidden])").length') == 2
        assert js('document.querySelector(".qf-sceau__repli") !== null')
        assert not errors, errors
        print('OK : repli sans JavaScript, aucune exception navigateur', flush=True)
        ws.close()
    finally:
        browser.terminate()
        browser.wait(timeout=10)
