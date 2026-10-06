# Økonomi

Privatøkonomi-app for logging av formue, gjeld, inntekter og utgifter over tid.

## Datamodell

- **Eiendeler og gjeld:** konkrete poster (f.eks. «Brukskonto DNB») ligger under kategorier (f.eks. «Bankkonto»). Verdier lagres i daterte snapshots. Et snapshot kan markeres komplett først når alle aktive eiendeler og gjeldsposter har en verdi.
- **Inntekter og utgifter:** konkrete poster (f.eks. «Lønn sykehus» eller «Diesel») ligger under kategorier og registreres som daterte transaksjoner.
- Alle rader er knyttet til den innloggede brukeren.

## Påkrevde GitHub Secrets

Eksisterende:
- `SFTP_USER`
- `SFTP_HOST`
- `SFTP_PASSWORD`
- `SFTP_REMOTE_PATH`

Legg også til:
- `GOOGLE_CLIENT_ID` – OAuth 2.0 Web client ID fra Google Cloud Console. Autorisert JavaScript-origin må inkludere `https://fundamentaleiendom.no`.

Valgfrie secrets dersom webhotellet ikke bruker standardverdiene:
- `DB_HOST` (standard: `localhost`)
- `DB_PORT` (standard: `3306`)
- `DB_USER` (standard: `coiurr9fr_db1394934`)
- `SFTP_PORT` (standard: `22`)

Databasen heter `coiurr9fr_db1394934`. Databasepassordet settes ved deploy til samme verdi som `SFTP_PASSWORD`.

## Deploy

Push til `main` kjører GitHub Actions og speiler `public/` til:

`${SFTP_REMOTE_PATH}/Økonomi/`

Workflowen lager `public/config.generated.php` under deploy. Denne filen committes aldri til repoet.

## Første oppstart

Tabellene opprettes automatisk ved første databasekall. Første gang en Google-bruker logger inn, opprettes standardkategorier for eiendeler, gjeld, inntekter og utgifter.
