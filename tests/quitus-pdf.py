"""Contrôle des PDF réels : couleurs d'en-tête et lecture des quatre QR à 300 dpi.

Prérequis : pypdf, pypdfium2, Pillow et zxing-cpp.
Exécuter auparavant tests/quitus-pdf.php. Aucune connexion à la base.
"""
import json
import sys
from collections import Counter
from pathlib import Path

import pypdfium2 as pdfium
import zxingcpp
from pypdf import PdfReader

root = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/ueb-quitus-qr-review")
manifest = json.loads((root / "manifest.json").read_text())
headers = (
    "RÉPUBLIQUE DU CAMEROUN", "Paix – Travail – Patrie", "UNIVERSITÉ D'EBOLOWA",
    "REPUBLIC OF CAMEROON", "Peace – Work – Fatherland", "THE UNIVERSITY OF EBOLOWA",
)
count = 0
for entry in manifest:
    path = root / entry["fichier"]
    q = entry["quitus"]
    reader = PdfReader(path, strict=True)
    assert len(reader.pages) == 1, path
    expected_color = tuple(int(entry["couleur"][i:i + 2], 16) for i in (1, 3, 5))
    state = {"color": None}
    found = Counter()

    def before(operator, args, cm, tm):
        if operator == b"rg":
            state["color"] = tuple(round(float(c) * 255) for c in args)

    def visit(text, cm, tm, font, size):
        for header in headers:
            if header in text:
                assert state["color"] == expected_color, (path, header, state["color"])
                found[header] += 1

    text = reader.pages[0].extract_text(visitor_operand_before=before, visitor_text=visit)
    assert all(found[header] == 4 for header in headers), (path, found)
    assert text.count(q["numero"]) == 4, path
    assert text.count(q["identifiant"]) == 4, path
    with pdfium.PdfDocument(str(path)) as pdf:
        page = pdf[0]
        rendered = page.render(scale=300 / 72).to_pil()
        codes = zxingcpp.read_barcodes(rendered)
        assert len(codes) == 4, (path, "QR décodés", len(codes))
        for code in codes:
            assert code.text == entry["qr"], (path, "contenu QR différent")
            for field in ("numero", "identifiant", "nom", "prenom", "lieu_naissance",
                          "sexe", "nationalite", "departement", "parcours", "annee_academique"):
                assert str(q[field]) in code.text, (path, field)
            assert "12/04/2002" in code.text, path
            amount = f'{q["montant"]:,}'.replace(",", " ") + " FCFA"
            assert amount in code.text, path
            identifier = "Matricule :" if q["type_identifiant"] == "matricule" else "N° de dossier :"
            assert identifier in code.text, path
            if q["type"] == "medicaux":
                assert "Frais médicaux · Paiement unique" in code.text, path
                assert "Tranche" not in code.text, path
            else:
                tranche = "Tranches 1 et 2" if q["tranche"] == 3 else "Tranche 2"
                assert "Droits universitaires · " + tranche in code.text, path
            assert "http" not in code.text, path
            assert q["email"] not in code.text and q["nom_urgence"] not in code.text, path
            count += 1
        if entry["fichier"] in ("FSJP-droits.pdf", "FSEG-medicaux.pdf", "identite-longue.pdf"):
            rendered.save(root / (path.stem + ".png"))
        page.close()

assert len(PdfReader(root / "quitus-couleurs-qr-18-pages.pdf").pages) == 18
print(f"{len(manifest)} PDF vérifiés : couleurs bilingues et {count} QR décodés à 300 dpi, "
      "avec accents, identifiants dossier/matricule et identité longue.")
