#!/usr/bin/env python3
"""Writes mpspayments/translations/{nl-NL,fr-FR}/*.xlf from the table below — the one place the module's
Dutch and French live. English is the source text in the code. Run it after changing a string, then
check every trans()/{l} call in the module has its row (build.sh does: tests/translations.py)."""
import hashlib
import os
import shutil
from xml.sax.saxutils import escape

HERE = os.path.dirname(os.path.abspath(__file__))

# domain -> [(english, dutch, french)]
STRINGS = {
    "Modules.Mpspayments.Admin": [
        ("MPS Payments", "MPS Payments", "MPS Payments"),
        ("Take payments in your PrestaShop shop on the MPS-hosted checkout page.",
         "Ontvang betalingen in uw PrestaShop-webshop op de betaalpagina van MPS.",
         "Encaissez les paiements de votre boutique PrestaShop sur la page de paiement hébergée par MPS."),
        ("MPS did not accept these credentials: %reason%", "MPS heeft deze gegevens niet aanvaard: %reason%",
         "MPS n'a pas accepté ces identifiants : %reason%"),
        ("Connected to MPS with a test key. It works for 24 hours and accepts only the MPS test cards; no card is charged.",
         "Verbonden met MPS met een testsleutel. Die werkt 24 uur en aanvaardt enkel de testkaarten van MPS; er wordt geen kaart gedebiteerd.",
         "Connecté à MPS avec une clé de test. Elle fonctionne 24 heures et n'accepte que les cartes de test MPS ; aucune carte n'est débitée."),
        ("Connected to MPS.", "Verbonden met MPS.", "Connecté à MPS."),
        ("Gateway URL", "Gateway-URL", "URL de la passerelle"),
        ("Set by _PS_MPS_PAYMENTS_GATEWAY_URL_ in config/defines_custom.inc.php.",
         "Ingesteld door _PS_MPS_PAYMENTS_GATEWAY_URL_ in config/defines_custom.inc.php.",
         "Défini par _PS_MPS_PAYMENTS_GATEWAY_URL_ dans config/defines_custom.inc.php."),
        ("Leave as is unless MPS asks you to change it.", "Niet wijzigen, tenzij MPS u daarom vraagt.",
         "Ne modifiez pas, sauf à la demande de MPS."),
        ("Payments on the checkout page MPS hosts. Card details never reach your shop.",
         "Betalingen op de betaalpagina van MPS. Kaartgegevens komen nooit in uw webshop terecht.",
         "Paiements sur la page de paiement hébergée par MPS. Les données de carte n'arrivent jamais dans votre boutique."),
        ("This payment method sends the order amount, order number and your shop's return addresses to MPS (Mobile Payment Solutions) to open each payment.",
         "Deze betaalmethode stuurt het bedrag en het nummer van de bestelling en de terugkeeradressen van uw webshop naar MPS (Mobile Payment Solutions) om elke betaling te openen.",
         "Ce moyen de paiement envoie le montant et le numéro de la commande ainsi que les adresses de retour de votre boutique à MPS (Mobile Payment Solutions) pour ouvrir chaque paiement."),
        ("The gateway URL must be an absolute address starting with https://. The previous one was kept.",
         "De gateway-URL moet een volledig adres zijn dat met https:// begint. Het vorige adres werd behouden.",
         "L'URL de la passerelle doit être une adresse complète commençant par https://. L'adresse précédente a été conservée."),
        ("MPS Payments needs the PHP curl extension. Ask your host to enable it, then install the module again.",
         "MPS Payments heeft de PHP-extensie curl nodig. Vraag uw hostingprovider die in te schakelen en installeer de module daarna opnieuw.",
         "MPS Payments nécessite l'extension PHP curl. Demandez à votre hébergeur de l'activer, puis réinstallez le module."),
        ("Client ID", "Client-ID", "ID client"),
        ("Paste the client ID, secret and webhook signing secret from the MPS platform (Online › Get started). A test key, whose client ID starts with test_, works for 24 hours and accepts only the MPS test cards: no card is charged and nothing is paid out.",
         "Plak de client-ID, het clientgeheim en het ondertekeningsgeheim voor webhooks uit het MPS-platform (Online › Aan de slag). Een testsleutel, waarvan de client-ID met test_ begint, werkt 24 uur en aanvaardt enkel de testkaarten van MPS: er wordt geen kaart gedebiteerd en niets uitbetaald.",
         "Collez l'ID client, le secret client et le secret de signature des webhooks depuis la plateforme MPS (En ligne › Commencer). Une clé de test, dont l'ID client commence par test_, fonctionne 24 heures et n'accepte que les cartes de test MPS : aucune carte n'est débitée et rien n'est versé."),
        ("Client secret", "Clientgeheim", "Secret client"),
        ("Saved. Leave empty to keep it.", "Opgeslagen. Laat leeg om het te behouden.", "Enregistré. Laissez vide pour le conserver."),
        ("Webhook signing secret", "Ondertekeningsgeheim voor webhooks", "Secret de signature des webhooks"),
        ("Shown with your key in the MPS platform. Payment notifications are only acted on when their signature matches; without a secret they are ignored and orders are settled by the module's own checks.",
         "Wordt bij uw sleutel getoond in het MPS-platform. Betaalmeldingen worden enkel verwerkt als hun handtekening klopt; zonder geheim worden ze genegeerd en worden bestellingen afgehandeld door de eigen controles van de module.",
         "Affiché avec votre clé dans la plateforme MPS. Les notifications de paiement ne sont traitées que si leur signature correspond ; sans secret, elles sont ignorées et les commandes sont réglées par les propres vérifications du module."),
        ("Remove the webhook signing secret", "Ondertekeningsgeheim voor webhooks verwijderen", "Supprimer le secret de signature des webhooks"),
        ("Debug log", "Foutopsporingslog", "Journal de débogage"),
        ("Log every call to MPS in Advanced Parameters › Logs (never secrets or payment links).",
         "Elke oproep naar MPS loggen in Geavanceerde instellingen › Logboeken (nooit geheimen of betaallinks).",
         "Journaliser chaque appel à MPS dans Paramètres avancés › Logs (jamais de secrets ni de liens de paiement)."),
        ("Title", "Titel", "Titre"),
        ("The name of this payment method at checkout. Empty: \"%default%\", in the shopper's language.",
         "De naam van deze betaalmethode bij het afrekenen. Leeg: \"%default%\", in de taal van de klant.",
         "Le nom de ce moyen de paiement lors du paiement. Vide : « %default% », dans la langue du client."),
        ("Description", "Beschrijving", "Description"),
        ("Shown under the title at checkout. Empty: a standard text in the shopper's language.",
         "Getoond onder de titel bij het afrekenen. Leeg: een standaardtekst in de taal van de klant.",
         "Affichée sous le titre lors du paiement. Vide : un texte standard dans la langue du client."),
        ("Scheduled checks", "Geplande controles", "Vérifications planifiées"),
        ("Call this address every 5 minutes (a crontab line, or the Cron tasks manager module). It confirms orders whose shopper closed the tab before returning, on a shop MPS cannot notify.",
         "Roep dit adres elke 5 minuten op (een crontab-regel, of de module Cron tasks manager). Zo worden bestellingen bevestigd waarvan de klant het tabblad sloot voor de terugkeer, in een webshop die MPS niet kan verwittigen.",
         "Appelez cette adresse toutes les 5 minutes (une ligne crontab, ou le module Cron tasks manager). Elle confirme les commandes dont le client a fermé l'onglet avant de revenir, sur une boutique que MPS ne peut pas notifier."),
        ("MPS payment %ref% for this cart was paid too, after the order was made. Refund it in MPS.",
         "MPS-betaling %ref% voor dit winkelmandje werd ook betaald, nadat de bestelling al was aangemaakt. Betaal ze terug in MPS.",
         "Le paiement MPS %ref% de ce panier a aussi été payé, après la création de la commande. Remboursez-le dans MPS."),
        ("MPS approved %approved% of %expected% (payment %ref%). Check before shipping.",
         "MPS keurde %approved% van %expected% goed (betaling %ref%). Controleer dit vóór verzending.",
         "MPS a approuvé %approved% sur %expected% (paiement %ref%). Vérifiez avant l'expédition."),
    ],
    "Modules.Mpspayments.Shop": [
        ("Pay with MPS", "Betalen met MPS", "Payer avec MPS"),
        ("Pay by card or with your banking app on the secure MPS payment page.",
         "Betaal met uw kaart of met uw bankapp op de beveiligde betaalpagina van MPS.",
         "Payez par carte ou avec votre application bancaire sur la page de paiement sécurisée de MPS."),
        ("The payment could not be started. Please try again or choose another payment method.",
         "De betaling kon niet worden gestart. Probeer het opnieuw of kies een andere betaalmethode.",
         "Le paiement n'a pas pu être lancé. Réessayez ou choisissez un autre moyen de paiement."),
        ("Your payment was not completed. Please try again or choose another payment method.",
         "Uw betaling is niet voltooid. Probeer het opnieuw of kies een andere betaalmethode.",
         "Votre paiement n'a pas abouti. Réessayez ou choisissez un autre moyen de paiement."),
        ("We are confirming your payment", "We bevestigen uw betaling", "Nous confirmons votre paiement"),
        ("This takes a few seconds. This page refreshes by itself.",
         "Dit duurt enkele seconden. Deze pagina vernieuwt vanzelf.",
         "Cela prend quelques secondes. Cette page s'actualise d'elle-même."),
        ("MPS has not confirmed your payment yet. You will receive an e-mail as soon as your order is confirmed; there is no need to pay again.",
         "MPS heeft uw betaling nog niet bevestigd. U ontvangt een e-mail zodra uw bestelling bevestigd is; u hoeft niet opnieuw te betalen.",
         "MPS n'a pas encore confirmé votre paiement. Vous recevrez un e-mail dès que votre commande sera confirmée ; inutile de payer à nouveau."),
        ("Your payment was received.", "Uw betaling is ontvangen.", "Votre paiement a bien été reçu."),
        ("Order reference:", "Bestelreferentie:", "Référence de la commande :"),
    ],
}

LOCALES = {"nl-NL": 1, "fr-FR": 2}

# Every folder of the module holds the module's redirecting index.php, these written ones too.
INDEX = os.path.join(HERE, "mpspayments", "index.php")

if __name__ == "__main__":
    translations = os.path.join(HERE, "mpspayments", "translations")
    for locale, column in LOCALES.items():
        folder = os.path.join(translations, locale)
        os.makedirs(folder, exist_ok=True)
        for target in (translations, folder):
            shutil.copyfile(INDEX, os.path.join(target, "index.php"))
        for domain, rows in STRINGS.items():
            units = "".join(
                f'      <trans-unit id="{hashlib.md5(row[0].encode()).hexdigest()}">\n'
                f"        <source>{escape(row[0])}</source>\n"
                f"        <target>{escape(row[column])}</target>\n"
                f"      </trans-unit>\n"
                for row in rows
            )
            path = os.path.join(folder, f"{domain.replace('.', '')}.{locale}.xlf")
            with open(path, "w", encoding="utf-8") as f:
                f.write(
                    '<?xml version="1.0" encoding="UTF-8"?>\n'
                    '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">\n'
                    f'  <file original="mpspayments" source-language="en-US" target-language="{locale}" datatype="plaintext">\n'
                    "    <body>\n" + units + "    </body>\n  </file>\n</xliff>\n"
                )
            print(os.path.relpath(path, HERE))
