# Zwei-Faktor-Anmeldung (TOTP)

Verlangt bei der Anmeldung neben dem Passwort einen sechsstelligen Code, den
eine App auf dem Telefon im Sekundentakt erzeugt. Wer nur das Passwort hat,
kommt damit nicht mehr hinein.

Das Verfahren ist TOTP nach RFC 6238 — dieselbe Technik, die auch andere Dienste
benutzen. Eine beliebige Authenticator-App tut es.

## Voraussetzungen

* owncloud.online 11.x
* PHP 8.4
* eine halbwegs richtig gehende Uhr auf Server und Telefon — der Code hängt an
  der Zeit, mehr als ein bis zwei Minuten Abweichung und nichts passt mehr

## Installation

Über den Market, oder von Hand:

```bash
cd /var/www/owncloud.online/apps
git clone https://github.com/BWTECH-github/twofactor_totp.git
cd twofactor_totp
composer install --no-dev
chown -R www-data:www-data .
sudo -u www-data php8.4 ../../occ app:enable twofactor_totp
```

## Einrichten (aus Sicht des Kontos)

1. *Einstellungen → Sicherheit*, dort den Punkt für die Zwei-Faktor-Anmeldung
   einschalten.
2. Den angezeigten QR-Code mit der Authenticator-App abfotografieren. Wer nicht
   fotografieren kann, tippt den daneben stehenden Schlüssel ab.
3. Den ersten Code aus der App eintragen und bestätigen. **Erst dieser Schritt
   schaltet den zweiten Faktor scharf** — ohne Bestätigung bleibt er unwirksam.

Ab der nächsten Anmeldung fragt der Server nach dem Code.

## Was mit Clients und WebDAV passiert

Programme, die kein Anmeldefenster zeigen — der Client für den Arbeitsplatz, die
mobilen Apps, WebDAV-Werkzeuge, `owncloudcmd` — können keinen zweiten Faktor
abfragen. Sie brauchen ein **App-Passwort** aus *Einstellungen → Sicherheit*
oder eine Anmeldung über OAuth2. Bestehende Verbindungen laufen weiter, neue
werden mit dem Kontopasswort abgewiesen.

## Erzwingen

Der Server selbst entscheidet, für wen ein zweiter Faktor Pflicht ist:
*Einstellungen → Administration → Sicherheit*. Dort lässt sich die Pflicht für
alle einschalten und einzelne Gruppen davon ausnehmen. Konten ohne
eingerichteten zweiten Faktor werden nach der Anmeldung zum Einrichten geführt.

Dieselben zwei Werte lassen sich auch von der Kommandozeile setzen — sie
gehören zur App `core`, nicht zu dieser App:

```bash
sudo -u www-data php8.4 occ config:app:set core enforce_2fa --value=yes
sudo -u www-data php8.4 occ config:app:set core enforce_2fa_excluded_groups \
  --value='["Technik"]'
```

## Wenn jemand ausgesperrt ist

Verlorenes Telefon, neu aufgesetztes Gerät, Uhr verstellt — dann hilft nur, den
hinterlegten Schlüssel zu löschen. Das Konto richtet danach neu ein.

```bash
# Schlüssel eines oder mehrerer Konten löschen
sudo -u www-data php8.4 occ twofactor_totp:delete-secret alice bob

# Schlüssel aller Konten löschen
sudo -u www-data php8.4 occ twofactor_totp:delete-secret --all
```

Weitere Befehle:

```bash
# Bestätigungsstatus setzen, etwa nach einer Migration
sudo -u www-data php8.4 occ twofactor_totp:set-secret-verification-status true --uid alice
sudo -u www-data php8.4 occ twofactor_totp:set-secret-verification-status true --all

# überzählige Schlüssel aufräumen, wenn ein Konto mehrere angesammelt hat
sudo -u www-data php8.4 occ twofactor_totp:delete-redundant-secret
```

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Code wird immer abgewiesen | Uhr auf Server oder Telefon geht falsch | Zeitabgleich (NTP) auf dem Server prüfen, in der App die Zeit synchronisieren |
| Nach dem Einrichten wird nicht gefragt | Der erste Code wurde nie bestätigt | Einrichtung wiederholen und den Code eingeben |
| Client meldet falsches Passwort | Der Client kann keinen zweiten Faktor abfragen | App-Passwort anlegen und dort eintragen |
| Konto kommt gar nicht mehr hinein | Schlüssel verloren | `occ twofactor_totp:delete-secret <konto>` |

## Tests

```bash
make test
```

Führt die PHP-Unit- und Integrationstests aus, und die JS-Tests, sofern unter
`js/` eine `package.json` liegt. Einzeln geht auch
`phpunit -c phpunit.xml` beziehungsweise `phpunit -c phpunit.integration.xml`.

## Herkunft

Fork der gleichnamigen ownCloud-App, gepflegt von der BW-Tech GmbH für
owncloud.online und PHP 8.4. Lizenz: AGPLv3.
