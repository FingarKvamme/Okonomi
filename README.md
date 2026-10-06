# Økonomi

Webversjon av det personlige økonomisystemet som tidligere har vært bygget i Excel. Første versjon speiler hovedstrukturen i de fem arbeidsbøkene uten å legge private økonomidata i GitHub.

## Excel-modellen som er speilet

- **Logg_Total Økonomi**: eiendeler, gjeld, netto formue, D/E, progresjon og investeringsallokering.
- **Logg_Inntekter og utgifter**: utgifter, inntekter, bruttoinntekter og kategorioppsummeringer.
- **Logg_Eiendom**: avstemming, fellesutgifter, utgifter/inntekter per enhet og månedlig resultat.
- **Logg_Aksjer**: porteføljeverdi, innskudd og avkastning.
- **Økonomisk Kontrollpanel**: overordnet kontrollpanel og budsjett.

## Personvern i v1

Repoet er offentlig. Derfor inneholder kildekoden **ingen tall fra Excel-filene**. Excel-import skjer i nettleseren med SheetJS, og data lagres i `localStorage` på den aktuelle enheten. Data sendes ikke til GitHub eller webserveren. Bruk siden **Data & sikkerhetskopi** for å laste ned/inn en JSON-backup.

Dette betyr også at v1 ikke synkroniserer mellom enheter. En senere versjon kan få innlogging og serverdatabase.

## Deploy

Push til `main` deployer innholdet i `public/` over SFTP. Følgende GitHub Actions secrets brukes:

- `SFTP_HOST`
- `SFTP_USER`
- `SFTP_PASSWORD`
- `SFTP_REMOTE_PATH`

`SFTP_REMOTE_PATH` skal peke på katalogen som serveres som `https://fundamentaleiendom.no/Økonomi/` (eller tilsvarende URL-kodet sti på serveren).

## Bruk

1. Åpne nettsiden.
2. Gå til **Data & sikkerhetskopi**.
3. Velg alle fem Excel-filene samtidig.
4. Kontroller oversikt, privatøkonomi, formue, eiendom, aksjer og budsjett.
5. Last ned en JSON-sikkerhetskopi når dataene er importert.

## Teknologi

Statisk HTML/CSS/JavaScript, Chart.js for grafer og SheetJS for lokal Excel-import. Ingen byggetrinn er nødvendig.
