"""Centre médico-social : cloisonnement avec la scolarité, de bout en bout, par HTTP.

Crée un compte du CMS, un compte de scolarité FS, un étudiant et son dossier
(droits universitaires + frais médicaux rattachés, reçus envoyés), puis
vérifie que chaque espace ne voit, n'ouvre et ne décide que son type de
quitus : listes, fiches, reçus, PDF, décisions sur la fiche et depuis la
ligne, connexion, sessions. Supprime toutes les données de test, même en
cas d'échec.

Usage : python3 tests/cms-securite.py   (XAMPP démarré, site sur http://localhost/inscription-ueb)
"""
import http.cookiejar, json, re, subprocess, urllib.parse, urllib.request
BASE = 'http://localhost/inscription-ueb'
WP = "$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_NAME']='localhost';$_SERVER['REQUEST_URI']='/';require '/opt/lampp/htdocs/inscription-ueb/wp-load.php';"

def php(code):
    return (subprocess.run(['/opt/lampp/bin/php', '-r', WP + code], capture_output=True, text=True).stdout.strip().splitlines() or [''])[-1]

PREPARER = r"""
global $wpdb; $annee = ueb_annee_academique()['code'];
$cms = ''; foreach ( ueb_roles() as $s => $r ) { if ( in_array( UEB_CAP_MEDICAUX, $r['permissions'], true ) && in_array( UEB_CAP_DECIDER_MEDICAUX, $r['permissions'], true ) ) { $cms = $s; } }
$a = wp_insert_user( array( 'user_login' => 'zzcms.agent', 'user_pass' => 'ZzcmsAgent2026', 'display_name' => 'ZZCMS Agent', 'role' => $cms ) );
$b = wp_insert_user( array( 'user_login' => 'zzcms.scolarite', 'user_pass' => 'ZzcmsSco2026', 'display_name' => 'ZZCMS Scolarité', 'role' => ueb_role_par_defaut( UEB_CAP_GESTION ) ) );
update_user_meta( $b, 'ueb_etablissement', 'FS' );
$wpdb->insert( 'ueb_insc_comptes', array( 'matricule' => 'ZZCMS1', 'telephone' => '699000099', 'mot_de_passe' => wp_hash_password( 'x' ), 'statut' => 'actif' ) );
$c = (int) $wpdb->insert_id;
$base = array( 'compte_id' => $c, 'etablissement' => 'FS', 'annee_academique' => $annee, 'identifiant' => 'ZZCMS1', 'type_identifiant' => 'matricule', 'nom' => 'ZZCMS', 'prenom' => 'Test', 'date_naissance' => '2003-01-01', 'lieu_naissance' => 'Ebolowa', 'sexe' => 'F', 'nationalite' => 'Camerounaise', 'departement' => 'Informatique', 'parcours' => 'L2', 'statut' => 'recu_envoye' );
$wpdb->insert( 'ueb_insc_quitus', $base + array( 'numero' => 'FS-2627-ZZCMS', 'code_verif' => 'zzcms-du-' . wp_generate_password( 8, false ), 'type' => 'droits', 'montant' => 25000, 'tranche' => 1 ) );
$du = (int) $wpdb->insert_id;
$wpdb->insert( 'ueb_insc_quitus', $base + array( 'numero' => 'FS-M-2627-ZZCMS', 'code_verif' => 'zzcms-fm-' . wp_generate_password( 8, false ), 'type' => 'medicaux', 'situation' => 'ancien', 'montant' => 3000, 'tranche' => 0, 'quitus_droits_id' => $du ) );
$fm = (int) $wpdb->insert_id;
$fichier = (string) $wpdb->get_var( 'SELECT fichier FROM ueb_insc_recus ORDER BY id DESC LIMIT 1' );
$recus = array();
foreach ( array( $du => 'tranche1', $fm => 'medicaux' ) as $q => $objet ) {
	$wpdb->insert( 'ueb_insc_recus', array( 'quitus_id' => $q, 'compte_id' => $c, 'fichier' => $fichier, 'nom_original' => 'zzcms.jpg', 'type_mime' => 'image/jpeg', 'taille' => 1000, 'objet' => $objet ) );
	$recus[] = (int) $wpdb->insert_id;
}
echo json_encode( array( 'cms' => $a, 'sco' => $b, 'du' => $du, 'fm' => $fm, 'recu_du' => $recus[0], 'recu_fm' => $recus[1], 'fichier' => '' !== $fichier, 'role' => $cms ) );
"""
NETTOYER = r"""
global $wpdb; require_once ABSPATH . 'wp-admin/includes/user.php';
$ids = $wpdb->get_col( "SELECT id FROM ueb_insc_quitus WHERE nom = 'ZZCMS'" );
if ( $ids ) { $wpdb->query( 'DELETE FROM ueb_insc_recus WHERE quitus_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); }
$wpdb->query( "DELETE FROM ueb_insc_quitus WHERE nom = 'ZZCMS'" );
$wpdb->query( "DELETE FROM ueb_insc_comptes WHERE matricule = 'ZZCMS1'" );
foreach ( array( 'zzcms.agent', 'zzcms.scolarite' ) as $l ) { $u = get_user_by( 'login', $l ); if ( $u ) { wp_delete_user( $u->ID ); } }
echo 'ok';
"""

def session(uid=0):
    jar = http.cookiejar.CookieJar()
    if uid:
        brut = php("$e=time()+600;echo LOGGED_IN_COOKIE.'='.rawurlencode(wp_generate_auth_cookie(%d,$e,'logged_in')).'; '.AUTH_COOKIE.'='.rawurlencode(wp_generate_auth_cookie(%d,$e,'auth'));" % (uid, uid))
        for morceau in brut.split('; '):
            nom, val = morceau.split('=', 1)
            jar.set_cookie(http.cookiejar.Cookie(0, nom, val, None, False, 'localhost.local', False, False, '/', True, False, None, False, None, None, {}))
    class SansSuivre(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), SansSuivre)

def get(op, chemin):
    try:
        r = op.open(BASE + chemin); return r.status, r.headers.get('Content-Type', ''), r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Content-Type', ''), e.read()

def page(op, chemin):
    st, _, corps = get(op, chemin); return st, corps.decode(errors='replace')

def post(op, chemin, donnees):
    corps = urllib.parse.urlencode(donnees, doseq=True).encode()
    try:
        r = op.open(BASE + chemin, corps); return r.status, r.headers.get('Location', '')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location', '')

def csrf(html):
    m = re.search(r'name="ueb_csrf" value="([^"]+)"', html); return m.group(1) if m else ''

def statut(qid):
    return php("global $wpdb;echo $wpdb->get_var($wpdb->prepare('SELECT CONCAT(statut,\"|\",IFNULL(verifie_par,0),\"|\",IFNULL(motif_rejet,\"\")) FROM ueb_insc_quitus WHERE id=%%d',%d));" % qid)

resultats = []
def verifier(nom, condition, detail=''):
    resultats.append(condition)
    print(('OK   ' if condition else 'ÉCHEC'), nom, ('— ' + str(detail) if detail and not condition else ''))

try:
    php(NETTOYER)
    d = json.loads(php(PREPARER))
    verifier('le registre des rôles propose un rôle du Centre médico-social', bool(d['role']))
    cms, sco = session(d['cms']), session(d['sco'])

    # --- Ce que chaque espace voit ---
    st, html = page(cms, '/cms/')
    verifier('le CMS ouvre son tableau de bord', st == 200 and 'Quitus en attente de vérification' in html and 'adm-attente' in html)
    verifier('la carte rouge mène à la liste filtrée', 'vue=quitus&#038;statut=recu_envoye' in html or 'vue=quitus&amp;statut=recu_envoye' in html)
    st, html = page(cms, '/cms/?vue=quitus&statut=recu_envoye')
    verifier('la liste du CMS montre les frais médicaux', 'FS-M-2627-ZZCMS' in html)
    verifier('la liste du CMS ne montre pas les droits', 'FS-2627-ZZCMS<' not in html and '>FS-2627-ZZCMS' not in html)
    st, html = page(cms, '/cms/?quitus=%d' % d['du'])
    verifier('un lien vers les droits ouvre, au CMS, les frais médicaux du dossier', 'data-quitus-fiche' in html and 'FS-M-2627-ZZCMS' in html and 'value="cms_statut"' in html)
    st, html = page(sco, '/scolarite/?vue=quitus')
    verifier('la liste de la scolarité montre les droits', '>FS-2627-ZZCMS<' in html)
    verifier('la liste de la scolarité ne montre plus les frais médicaux', 'FS-M-2627-ZZCMS' not in html)
    st, html = page(sco, '/scolarite/?quitus=%d' % d['fm'])
    verifier('ouverte depuis le quitus médical, la fiche de la scolarité montre les droits seuls', 'data-quitus-fiche' in html and 'FS-2627-ZZCMS' in html and 'FS-M-2627-ZZCMS' not in html)
    st, html = page(sco, '/scolarite/')
    verifier('la scolarité a la carte rouge en tête de son tableau de bord', 'adm-attente' in html)
    st, html = page(sco, '/cms/')
    verifier('la scolarité n’entre pas dans l’espace du CMS', 'name="ueb_connexion_cms"' in html and 'adm-attente' not in html)

    # --- Reçus et PDF ---
    if d['fichier']:
        verifier('le CMS lit le reçu des frais médicaux', get(cms, '/recu/%d/' % d['recu_fm'])[0] == 200)
        verifier('le CMS ne lit pas le reçu des droits', get(cms, '/recu/%d/' % d['recu_du'])[0] == 404)
        verifier('la scolarité lit le reçu des droits', get(sco, '/recu/%d/' % d['recu_du'])[0] == 200)
        verifier('la scolarité ne lit plus le reçu des frais médicaux', get(sco, '/recu/%d/' % d['recu_fm'])[0] == 404)
    st, type_, corps = get(cms, '/cms/?quitus=%d&pdf=1' % d['fm'])
    verifier('le CMS télécharge le PDF du quitus médical', st == 200 and corps[:4] == b'%PDF', type_)
    st, type_, corps = get(cms, '/cms/?quitus=%d&pdf=1' % d['du'])
    verifier('le CMS ne télécharge pas le PDF des droits', corps[:4] != b'%PDF')

    # --- Décisions sur la fiche ---
    jeton_cms = csrf(page(cms, '/cms/?quitus=%d' % d['fm'])[1])
    jeton_sco = csrf(page(sco, '/scolarite/?quitus=%d' % d['du'])[1])
    st, _ = post(cms, '/cms/', {'ueb_action': 'cms_statut', 'ueb_csrf': jeton_cms, 'quitus_id': d['du'], 'statut': 'verifie'})
    verifier('le CMS ne peut pas décider des droits', st == 403 and statut(d['du']).startswith('recu_envoye'), st)
    st, _ = post(sco, '/scolarite/', {'ueb_action': 'gestion_statut', 'ueb_csrf': jeton_sco, 'quitus_id': d['fm'], 'statut': 'verifie'})
    verifier('la scolarité ne peut plus décider des frais médicaux', st == 403 and statut(d['fm']).startswith('recu_envoye'), st)
    st, _ = post(cms, '/cms/', {'ueb_action': 'cms_statut', 'quitus_id': d['fm'], 'statut': 'verifie'})
    verifier('sans jeton de session, la décision est refusée', statut(d['fm']).startswith('recu_envoye'))
    post(cms, '/cms/', {'ueb_action': 'cms_statut', 'ueb_csrf': jeton_cms, 'quitus_id': d['fm'], 'statut': 'rejete', 'motif': 'non'})
    verifier('un refus sans motif suffisant est refusé', statut(d['fm']).startswith('recu_envoye'))
    st, loc = post(cms, '/cms/', {'ueb_action': 'cms_statut', 'ueb_csrf': jeton_cms, 'quitus_id': d['fm'], 'statut': 'rejete', 'motif': 'Le reçu est illisible.'})
    verifier('le CMS refuse avec un motif, enregistré à son nom', statut(d['fm']) == 'rejete|%d|Le reçu est illisible.' % d['cms'], statut(d['fm']))
    verifier('après la décision, retour à la fiche du CMS', '/cms/' in loc and 'quitus=%d' % d['fm'] in loc, loc)
    post(cms, '/cms/', {'ueb_action': 'cms_statut', 'ueb_csrf': jeton_cms, 'quitus_id': d['fm'], 'statut': 'recu_envoye'})
    verifier('le CMS annule sa décision', statut(d['fm']).startswith('recu_envoye|0|'))

    # --- Validation depuis la ligne du registre ---
    st, loc = post(sco, '/scolarite/', {'ueb_action': 'gestion_valider', 'ueb_csrf': jeton_sco, 'quitus_id': d['du'], 'retour[statut]': 'recu_envoye'})
    verifier('« Valider » de la scolarité valide les droits', statut(d['du']).startswith('verifie|%d' % d['sco']))
    verifier('… sans toucher aux frais médicaux du dossier', statut(d['fm']).startswith('recu_envoye'))
    st, loc = post(cms, '/cms/', {'ueb_action': 'cms_valider', 'ueb_csrf': jeton_cms, 'quitus_id': d['fm'], 'retour[statut]': 'recu_envoye'})
    verifier('« Valider » du CMS valide les frais médicaux', statut(d['fm']).startswith('verifie|%d' % d['cms']))
    verifier('retour à la liste filtrée du CMS, sur la ligne validée', '/cms/' in loc and 'statut=recu_envoye' in loc and '#dossier-%d' % d['fm'] in loc, loc)

    # --- Connexion et sessions ---
    anonyme = session()
    st, html = page(anonyme, '/cms/')
    nonce = re.search(r'name="ueb_connexion_nonce" value="([^"]+)"', html)
    st, loc = post(anonyme, '/cms/', {'ueb_connexion_cms': 1, 'ueb_connexion_nonce': nonce.group(1) if nonce else '', 'identifiant': 'zzcms.agent', 'mot_de_passe': 'ZzcmsAgent2026'})
    verifier('le compte du CMS se connecte sur son espace', st in (301, 302) and '/cms/' in loc, (st, loc))
    anonyme2 = session()
    html = page(anonyme2, '/cms/')[1]
    nonce = re.search(r'name="ueb_connexion_nonce" value="([^"]+)"', html)
    corps = urllib.parse.urlencode({'ueb_connexion_cms': 1, 'ueb_connexion_nonce': nonce.group(1) if nonce else '', 'identifiant': 'zzcms.scolarite', 'mot_de_passe': 'ZzcmsSco2026'}).encode()
    try:
        reponse = anonyme2.open(BASE + '/cms/', corps).read().decode()
    except urllib.error.HTTPError as e:
        reponse = e.read().decode()
    verifier('un compte de scolarité est refusé à la connexion du CMS', 'pas accès à l\'espace du Centre médico-social' in reponse.replace('&#039;', "'"))
    verifier('après connexion, le CMS est l’espace du compte', '/cms/' in php('echo ueb_url_espace_du_compte(%d);' % d['cms']))
    php("$m=WP_Session_Tokens::get_instance(%d);$m->create(time()+600);$m->create(time()+600);echo 1;" % d['cms'])
    avant = int(php("echo count(WP_Session_Tokens::get_instance(%d)->get_all());" % d['cms']))
    jeton = csrf(page(cms, '/cms/?vue=securite')[1])
    post(cms, '/cms/', {'ueb_action': 'personnel_fermer_sessions', 'ueb_csrf': jeton})
    apres = int(php("echo count(WP_Session_Tokens::get_instance(%d)->get_all());" % d['cms']))
    verifier('« Fermer les autres sessions » n’en garde qu’une', avant >= 3 and apres == 1, (avant, apres))
finally:
    print('nettoyage :', php(NETTOYER))

print(f"\n{sum(resultats)}/{len(resultats)} contrôles réussis")
raise SystemExit(0 if all(resultats) else 1)
