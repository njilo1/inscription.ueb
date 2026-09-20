"""Tests Chromium des aperçus fictifs générés par inscription.php (sans Node)."""
import base64
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.request
import websocket

ROOT = pathlib.Path('/tmp/ueb-inscription-review')
with tempfile.TemporaryDirectory(prefix='ueb-inscription-chromium-', ignore_cleanup_errors=True) as profile:
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
                raise RuntimeError('Chromium ne peut pas démarrer dans cet environnement.')
            time.sleep(.1)
        port = port_file.read_text().splitlines()[0]
        info = json.load(urllib.request.urlopen('http://127.0.0.1:' + port + '/json/version'))
        ws = websocket.create_connection(info['webSocketDebuggerUrl'], suppress_origin=True, timeout=10)
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
        def load(name, width=1280):
            call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False})
            url = (ROOT / (name + '.html')).as_uri()
            call('Page.navigate', {'url': url})
            for _ in range(50):
                time.sleep(.08)
                if js('location.href === ' + json.dumps(url) + ' && document.readyState === "complete"'):
                    break
            js('document.fonts.ready')
        def total(expected):
            assert js('document.querySelector("[data-total-paiement]").textContent') == expected + ' FCFA'
            assert js('document.querySelector("[data-recap-total]").textContent') == expected + ' FCFA'
        def change(selector, value=None):
            code = 'const e=document.querySelector(' + json.dumps(selector) + ');'
            code += 'e.click();' if value is None else 'e.value=' + json.dumps(value) + ';e.dispatchEvent(new Event("change",{bubbles:true}));'
            js('(()=>{' + code + '})()')
        for name, one, all_ in [('ancien', '28 000', '53 000'), ('reprise', '30 000', '55 000'), ('nouveau', '25 000', '50 000')]:
            load(name)
            total(one)
            assert js('document.querySelector("[data-montant]").readOnly')
            change('input[name=tranche][value="3"]')
            total(all_)
            change('input[name=tranche][value="1"]')
            total(one)
            if name == 'nouveau':
                assert not js('document.querySelector("select[name=situation]").disabled')
                choices = js('[...document.querySelector("[name=filiere_id]").options].filter(o=>o.value).map(o=>o.value)')
                assert len(choices) == 3
                for choice in choices:
                    change('[name=filiere_id]', choice)
                    total(one)
            else:
                change('select[name=situation]', 'reprise' if name == 'ancien' else 'ancien')
                total('30 000' if name == 'ancien' else '28 000')
        load('deuxieme')
        total('25 000')
        assert js('[...document.querySelectorAll("input[name=tranche]")].map(e=>e.value)') == ['2']
        assert '1 page' in js('document.querySelector("[data-documents-paiement]").textContent')
        assert js('[...document.querySelectorAll("[data-cms-champ]")].every(e=>!e.required)')
        load('nouveau')
        assert js('[...document.querySelectorAll("[data-cms-champ]")].every(e=>!e.required)')
        load('ancien')
        assert js('[...document.querySelectorAll("[data-cms-champ]")].every(e=>e.required && e.getClientRects().length)')
        change('select[name=situation]', 'nouveau')
        assert js('[...document.querySelectorAll("[data-cms-champ]")].every(e=>!e.required)')
        change('select[name=situation]', 'ancien')
        assert js('[...document.querySelectorAll("[data-cms-champ]")].every(e=>e.required)')

        # Reproduire les trois erreurs, suivre les liens et soumettre après correction.
        load('erreurs-cms', 375)
        assert js('document.activeElement.hasAttribute("data-resume-erreurs")')
        assert js('document.querySelectorAll("[data-lien-erreur]").length') == 3
        for field in ['nom_urgence', 'numero_urgence', 'adresse_urgence']:
            assert js('document.querySelector("[name=' + field + ']").getClientRects().length > 0')
            change('[data-lien-erreur][href="#champ-' + field + '"]')
            assert js('document.activeElement.name') == field
        assert not js('document.documentElement.scrollWidth > innerWidth')
        js('document.querySelector("[data-quitus]").addEventListener("submit", e=>{window.envoiAutorise=!e.defaultPrevented;e.preventDefault()})')
        change('button[type=submit]')
        assert js('window.envoiAutorise') is False
        assert js('document.activeElement.name') == 'nom_urgence'
        assert not js('document.querySelector("button[type=submit]").hasAttribute("aria-busy")')
        change('[name=nom_urgence]', 'Jean Test')
        change('[name=numero_urgence]', '+237 699 11 11 11')
        change('[name=adresse_urgence]', 'Quartier Angalé, Ebolowa')
        change('button[type=submit]')
        assert js('window.envoiAutorise') is True
        js('document.querySelector("[data-resume-erreurs]").scrollIntoView({block:"start",behavior:"instant"})')
        shot = call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': False})
        (ROOT / 'erreurs-cms-375.png').write_bytes(base64.b64decode(shot['data']))

        load('ancien')
        change('input[name=etablissement][value="FSEG"]')
        assert js('document.querySelector("[name=filiere_id]").value') == ''
        assert js('document.querySelector("[data-total-paiement]").textContent') == '—'
        assert js('JSON.parse(document.querySelector("#donnees-paiement").textContent).formations.filter(f=>[...document.querySelector("[name=filiere_id]").options].some(o=>o.value===String(f.id))).every(f=>f.etablissement==="FSEG")')
        load('ancien')
        pro = js('JSON.parse(document.querySelector("#donnees-paiement").textContent).formations.find(f=>f.type_formation==="pro")')
        change('input[name=etablissement][value="' + pro['etablissement'] + '"]')
        change('[name=filiere_id]', str(pro['id']))
        assert not js('document.querySelector("[data-montant]").readOnly')
        change('[data-montant]', '85000')
        total('88 000')
        for width in [1280, 768, 375, 320]:
            load('reprise', width)
            assert js('document.documentElement.scrollWidth <= innerWidth'), ('débordement', width)
            change('input[name=tranche][value="3"]')
            total('55 000')
            js('document.querySelector("#section-paiement").scrollIntoView({block:"start",behavior:"instant"})')
            js('new Promise(resolve=>setTimeout(resolve,150))')
            shot = call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': False})
            (ROOT / ('formulaire-' + str(width) + '.png')).write_bytes(base64.b64decode(shot['data']))
            js('document.querySelector(".quitus-final").scrollIntoView({block:"start",behavior:"instant"})')
            bounds = js('(()=>{const r=document.querySelector(".quitus-final").getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()')
            shot = call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': True, 'clip': bounds})
            (ROOT / ('recapitulatif-' + str(width) + '.png')).write_bytes(base64.b64decode(shot['data']))
        call('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-motion', 'value': 'reduce'}]})
        change('input[name=tranche][value="1"]')
        total('30 000')
        assert not errors, errors
        from espace_ui import verifier_espace
        verifier_espace(call, js, change, ROOT)
        assert not errors, errors
        print('Chromium : erreurs CMS visibles et accessibles, correction puis soumission autorisée, champs requis selon les documents, paiements et affichage 320–1280 px vérifiés ; aucune erreur JavaScript.')
        ws.close()
    finally:
        browser.terminate()
        browser.wait(timeout=10)
