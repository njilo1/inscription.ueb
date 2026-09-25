"""Rôles dynamiques : contrôles de sécurité de bout en bout, par HTTP, sur le site local.

Crée un rôle « TEST Direction FS » (diriger + suivi des paiements, portée FS)
et son compte, vérifie chaque refus côté serveur (escalade de permissions ou
de portée, nonce, rôle administrateur, comptes au-delà de ses droits, portée
des scolarités existantes, suppression avec réaffectation, suspension),
puis supprime toutes les données de test, même en cas d'échec.

Usage : python3 tests/roles-securite.py   (XAMPP démarré, site sur http://localhost/inscription-ueb)
"""
import http.cookiejar, re, subprocess, sys, urllib.parse, urllib.request
BASE = 'http://localhost/inscription-ueb'
WP = "$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_NAME']='localhost';$_SERVER['REQUEST_URI']='/';require '/opt/lampp/htdocs/inscription-ueb/wp-load.php';"

def php(code):
    return subprocess.run(['/opt/lampp/bin/php', '-r', WP + code], capture_output=True, text=True).stdout.strip().splitlines()[-1:] or ['']

def cookies(uid):
    return php("$e=time()+600;echo LOGGED_IN_COOKIE.'='.rawurlencode(wp_generate_auth_cookie(%d,$e,'logged_in')).'; '.AUTH_COOKIE.'='.rawurlencode(wp_generate_auth_cookie(%d,$e,'auth'));" % (uid, uid))[0]

PREPARER = ("wp_set_current_user(1);$s=ueb_nouveau_slug_role();ueb_enregistrer_role($s,array('nom'=>'TEST Direction FS','portee'=>'un','etablissements'=>array(),'permissions'=>array(UEB_CAP_DIRECTION,'ueb_voir_paiements'),'historique'=>false,'cree_le'=>current_time('mysql'),'modifie_le'=>current_time('mysql'),'modifie_par'=>1));"
            "$r=ueb_creer_compte_agent(array('login'=>'test.direction.fs','nom'=>'Test Direction FS','role'=>$s,'etablissement'=>'FS','mot_de_passe'=>'TestDir2026'));echo is_wp_error($r)?0:$r[0];")
NETTOYER = ("require_once ABSPATH.'wp-admin/includes/user.php';foreach(array('test.direction.fs','test.lecteur.fs','test.lecteur2.fs') as $l){$u=get_user_by('login',$l);if($u){wp_delete_user($u->ID);}}"
            "foreach(ueb_roles() as $s=>$r){if(0===strpos($r['nom'],'TEST ')){ueb_retirer_role($s);}}echo 'ok';")
def session(uid):
    jar = http.cookiejar.CookieJar()
    if uid:
        brut = cookies(uid)
        for morceau in brut.split('; '):
            nom, val = morceau.split('=', 1)
            jar.set_cookie(http.cookiejar.Cookie(0, nom, val, None, False, 'localhost.local', False, False, '/', True, False, None, False, None, None, {}))
    class SansSuivre(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), SansSuivre)

def get(op, chemin):
    try:
        r = op.open(BASE + chemin); return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

def jetons(html, action):
    csrf = re.search(r'name="ueb_csrf" value="([^"]+)"', html)
    bloc = html[html.find('value="' + action + '"'):]
    nonce = re.search(r'name="ueb_nonce_direction" value="([^"]+)"', bloc)
    return (csrf.group(1) if csrf else ''), (nonce.group(1) if nonce else '')

def post(op, chemin, donnees):
    corps = urllib.parse.urlencode(donnees, doseq=True).encode()
    try:
        r = op.open(BASE + chemin, corps); return r.status, r.headers.get('Location', ''), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location', ''), e.read().decode()

def flash(op):
    _, html = get(op, '/direction/?vue=personnel')
    m = re.findall(r'class="alerte alerte--(\w+)[^>]*>.*?<p>(.*?)</p>', html, re.S)
    return m[-1] if m else ('', '')

resultats = []
def verifier(nom, condition, detail=''):
    resultats.append((condition, nom))
    print(('OK   ' if condition else 'ÉCHEC'), nom, ('— ' + detail if detail and not condition else ''))

# --- Direction à portée FS, permissions : diriger + suivi des paiements ---
echecs = []
try:
    UID = int(php(PREPARER)[0] or 0)
    assert UID, 'préparation impossible'
    d = session(UID)
    st, html = get(d, '/direction/?vue=role')
    verifier('la Direction FS ouvre l’assistant', st == 200 and 'Que peut-il faire' in html)
    verifier('permission non détenue affichée désactivée', 'value="ueb_gerer_quitus"  disabled' in html or re.search(r'value="ueb_gerer_quitus"[^>]*disabled', html) is not None)
    csrf, nonce = jetons(html, 'direction_role_enregistrer')
    base = {'ueb_action': 'direction_role_enregistrer', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'role': ''}

    def essai_role(nom, portee, perms, etabs=()):
        st, loc, _ = post(d, '/direction/', dict(base, nom=nom, portee=portee, **{'permissions[]': list(perms), 'etablissements[]': list(etabs)}))
        return flash(d)

    t, m = essai_role('Escalade quitus', 'un', ['ueb_gerer_quitus'])
    verifier('refus : permission non détenue (quitus)', t == 'erreur' and 'dépasserait' in m, m)
    t, m = essai_role('Escalade portée', 'tous', ['ueb_voir_paiements'])
    verifier('refus : portée « tous » depuis une portée FS', t == 'erreur', m)
    t, m = essai_role('Escalade établissement', 'plusieurs', ['ueb_voir_paiements'], ['FS', 'FSEG'])
    verifier('refus : établissement hors portée (FSEG)', t == 'erreur', m)
    t, m = essai_role('Escalade capacité inventée', 'un', ['manage_options', 'edit_users'])
    verifier('refus : capacités système hors liste blanche', t == 'erreur', m)
    st, loc, _ = post(d, '/direction/', dict(base, ueb_nonce_direction='faux', nom='Sans nonce', portee='un', **{'permissions[]': ['ueb_voir_paiements']}))
    t, m = flash(d)
    verifier('refus : nonce invalide', 'expiré' in m, m)
    t, m = essai_role('TEST Lecteur paiements FS', 'un', ['ueb_voir_paiements'])
    verifier('accepté : rôle dans ses droits', t == 'succes', m)

    # création de comptes
    st, html = get(d, '/direction/?vue=personnel')
    csrf, nonce = jetons(html, 'direction_compte_creer')
    slug_lecteur = re.search(r'<option value="(ueb_r_[a-z0-9]+)" data-portee="un">TEST Lecteur paiements FS', html)
    slug_lecteur = slug_lecteur.group(1) if slug_lecteur else ''
    verifier('le nouveau rôle est proposé à l’attribution', bool(slug_lecteur))
    verifier('le rôle Scolarité (plus de droits) n’est pas proposé', 'value="ueb_scolarite"' not in html)
    cc = {'ueb_action': 'direction_compte_creer', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'login': 'test.lecteur.fs', 'nom': 'Lecteur FS', 'email': ''}
    post(d, '/direction/', dict(cc, role=slug_lecteur, etablissement='FSEG'))
    t, m = flash(d)
    verifier('refus : compte rattaché à FSEG (hors portée)', t == 'erreur', m)
    post(d, '/direction/', dict(cc, role='ueb_scolarite', etablissement='FS'))
    t, m = flash(d)
    verifier('refus : attribuer un rôle aux droits supérieurs', t == 'erreur', m)
    post(d, '/direction/', dict(cc, role='administrator', etablissement='FS'))
    t, m = flash(d)
    verifier('refus : attribuer « administrator »', t == 'erreur', m)
    post(d, '/direction/', dict(cc, role=slug_lecteur, etablissement='FS'))
    t, m = flash(d)
    verifier('accepté : compte FS avec le rôle autorisé', t == 'succes', m)

    # agir sur un compte au-delà de ses droits (agent scolarité FS, id 9)
    st, html = get(d, '/direction/?vue=personnel')
    csrf, nonce = jetons(html, 'direction_compte_etat')
    post(d, '/direction/', {'ueb_action': 'direction_compte_etat', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'agent_id': 9})
    verifier('refus : suspendre un agent au rôle supérieur', 'Suspendu' not in re.search(r'fs\.fs.*?</tr>', get(d, '/direction/?vue=personnel')[1], re.S).group(0) if re.search(r'fs\.fs.*?</tr>', get(d, '/direction/?vue=personnel')[1], re.S) else True)
    csrf, nonce = jetons(html, 'direction_compte_mdp')
    post(d, '/direction/', {'ueb_action': 'direction_compte_mdp', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'agent_id': 1})
    _, html2 = get(d, '/direction/?vue=personnel')
    verifier('refus : réinitialiser le mot de passe de l’administrateur', 'Mot de passe provisoire pour <b>neo' not in html2)

    # accès par capacité
    s9 = session(9)
    st, html = get(s9, '/direction/')
    verifier('un agent sans « diriger » ne voit pas la Direction', 'Ce compte n' in html and 'Rôles et accès' not in html)
    st, html = get(s9, '/scolarite/?quitus=3')
    verifier('scolarité FS : fiche d’un quitus FSJP refusée (portée)', 'FSJP-2627-000001' not in html and 'dossier-entete' not in html)
    st, html = get(s9, '/scolarite/?quitus=19')
    verifier('scolarité FS : fiche d’un quitus FS ouverte', 'dossier-entete' in html and 'FS-M-2627-000002' in html)
    st, html = get(s9, '/scolarite/?quitus=3&pdf=1')
    verifier('scolarité FS : PDF d’un quitus FSJP refusé', not html.startswith('%PDF'))
    st, html = get(s9, '/scolarite/?vue=quitus')
    verifier('scolarité FS : liste limitée à FS', 'FSJP-2627' not in html and 'FS-2627' in html)
    s12 = session(12)
    st, html = get(s12, '/cellule-informatique/')
    verifier('cellule FS historique : espace « Comptes étudiants » toujours ouvert', st == 200 and 'Comptes étudiants' in html and 'Ce compte n' not in html)
    st, html = get(d, '/scolarite/')
    verifier('Direction FS avec suivi : espace scolarité en vue Paiements seulement', 'Suivi des paiements' in html and 'À vérifier' not in html)
    st, html = get(session(0), '/direction/')
    verifier('visiteur non connecté : écran de connexion', 'bo-connexion' in html and 'Rôles et accès' not in html)

    # suppression d'un rôle utilisé : réaffectation exigée, aucun compte supprimé
    st, html = get(d, '/direction/')
    def dialogue(html, nom):
        m = re.search(r'<dialog class="dialogue" id="suppr-(ueb_r_[a-z0-9]+)"[^>]*>(?:(?!</dialog>).)*?Supprimer « ' + re.escape(nom) + ' » \\?(?:(?!</dialog>).)*</dialog>', html, re.S)
        return m.group(1), m.group(0)
    slug, bloc = dialogue(html, 'TEST Lecteur paiements FS')
    csrf = re.search(r'name="ueb_csrf" value="([^"]+)"', bloc).group(1)
    nonce = re.search(r'name="ueb_nonce_direction" value="([^"]+)"', bloc).group(1)
    sup = {'ueb_action': 'direction_role_supprimer', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'role': slug}
    post(d, '/direction/', dict(sup, confirmation='SUPPRIMER'))
    t, m = flash(d)
    verifier('refus : supprimer un rôle utilisé sans réaffectation', t == 'erreur' and 'reprendra' in m, m)
    post(d, '/direction/', dict(sup, confirmation='non', remplacant=slug))
    t, m = flash(d)
    verifier('refus : suppression sans saisir SUPPRIMER', t == 'erreur', m)
    post(d, '/direction/', dict(sup, confirmation='SUPPRIMER', remplacant='ueb_scolarite'))
    t, m = flash(d)
    verifier('refus : réaffecter vers un rôle aux droits supérieurs', t == 'erreur', m)
    post(d, '/direction/', dict(sup, confirmation='SUPPRIMER', remplacant='ueb_cellule'))
    t, m = flash(d)
    verifier('refus : réaffecter vers un rôle non détenu (comptes étudiants)', t == 'erreur', m)

    # réaffectation réelle vers un second rôle dans ses droits
    _, html = get(d, '/direction/?vue=role')
    csrf2, nonce2 = jetons(html, 'direction_role_enregistrer')
    post(d, '/direction/', {'ueb_action': 'direction_role_enregistrer', 'ueb_csrf': csrf2, 'ueb_nonce_direction': nonce2, 'role': '', 'nom': 'TEST Lecteur bis FS', 'portee': 'un', 'permissions[]': ['ueb_voir_paiements']})
    _, html = get(d, '/direction/')
    cible, _ = dialogue(html, 'TEST Lecteur bis FS')
    slug, bloc = dialogue(html, 'TEST Lecteur paiements FS')
    csrf = re.search(r'name="ueb_csrf" value="([^"]+)"', bloc).group(1)
    nonce = re.search(r'name="ueb_nonce_direction" value="([^"]+)"', bloc).group(1)
    post(d, '/direction/', {'ueb_action': 'direction_role_supprimer', 'ueb_csrf': csrf, 'ueb_nonce_direction': nonce, 'role': slug, 'confirmation': 'supprimer', 'remplacant': cible})
    t, m = flash(d)
    verifier('suppression avec réaffectation', t == 'succes' and 'réaffecté' in m, m)
    etat = php("$u=get_user_by('login','test.lecteur.fs');echo $u?implode(',',$u->roles).'|'.get_user_meta($u->ID,'ueb_etablissement',true).'|'.(ueb_role('%s')?'present':'absent'):'absent';" % slug)[0]
    verifier('compte conservé et réaffecté, rôle supprimé', etat == cible + '|FS|absent', etat)
    # suspension
    php("update_user_meta(%d,'ueb_agent_suspendu',1);echo 1;" % UID)
    st, html = get(session(UID), '/direction/')
    verifier('compte suspendu : plus d’accès à la Direction', 'Rôles et accès' not in html)
    php("delete_user_meta(%d,'ueb_agent_suspendu');echo 1;" % UID)
    st, html = get(session(UID), '/direction/')
    verifier('compte rétabli : accès retrouvé', 'Rôles et accès' in html)
    print(sum(1 for c, _ in resultats if c), '/', len(resultats), 'vérifications réussies')

finally:
    print('Nettoyage des données de test :', php(NETTOYER)[0])
