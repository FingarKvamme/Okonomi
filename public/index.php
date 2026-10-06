<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$basePath = app_base_path();
$config = app_config();
$clientConfig = [
    'apiUrl' => $basePath . '/api.php',
    'basePath' => $basePath,
    'googleClientId' => (string)($config['google_client_id'] ?? ''),
];
?>
<!doctype html>
<html lang="nb">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7f4">
    <title>Økonomi</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/app.css?v=1">
    <script>window.APP_CONFIG = <?= json_encode($clientConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
    <script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/app.js?v=2" defer></script>
    <script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/assets/import.js?v=1" defer></script>
</head>
<body>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <main id="login-view" class="login-shell" hidden>
        <section class="login-card">
            <div class="brand-mark">Ø</div>
            <p class="eyebrow">Privatøkonomi over tid</p>
            <h1>Økonomi</h1>
            <p class="lead">Følg formue, gjeld, inntekter og utgifter med konsistente økonomiske øyeblikksbilder.</p>
            <div id="google-signin" class="google-slot"></div>
            <p id="login-config-warning" class="setup-warning" hidden>
                Google-innlogging er ikke konfigurert ennå. Legg <code>GOOGLE_CLIENT_ID</code> i GitHub Secrets og kjør deploy på nytt.
            </p>
            <p class="privacy-note">Dataene dine holdes adskilt fra andre brukere og lagres på serverens database.</p>
        </section>
    </main>

    <div id="app-view" class="app-shell" hidden>
        <header class="topbar">
            <div class="brand">
                <div class="brand-mark small">Ø</div>
                <div>
                    <strong>Økonomi</strong>
                    <span>Privatøkonomi</span>
                </div>
            </div>
            <div class="user-area">
                <img id="user-avatar" class="avatar" alt="" hidden>
                <div class="user-copy">
                    <strong id="user-name"></strong>
                    <span id="user-email"></span>
                </div>
                <button id="logout-btn" class="button ghost compact" type="button">Logg ut</button>
            </div>
        </header>

        <nav class="tabs" aria-label="Hovednavigasjon">
            <button class="tab active" data-view="overview" type="button">Oversikt</button>
            <button class="tab" data-view="log" type="button">Loggfør</button>
            <button class="tab" data-view="setup" type="button">Kategorier og poster</button>
        </nav>

        <main class="content">
            <section id="view-overview" class="view active">
                <div class="page-heading">
                    <div>
                        <p class="eyebrow">Status</p>
                        <h1>Din økonomi</h1>
                    </div>
                    <button id="refresh-summary" class="button secondary" type="button">Oppdater</button>
                </div>

                <div class="metric-grid">
                    <article class="metric-card emphasis">
                        <span>Nettoformue</span>
                        <strong id="metric-networth">—</strong>
                        <small id="metric-snapshot-date">Ingen komplett registrering ennå</small>
                    </article>
                    <article class="metric-card">
                        <span>Eiendeler</span>
                        <strong id="metric-assets">—</strong>
                        <small>Siste komplette snapshot</small>
                    </article>
                    <article class="metric-card">
                        <span>Gjeld</span>
                        <strong id="metric-liabilities">—</strong>
                        <small>Siste komplette snapshot</small>
                    </article>
                    <article class="metric-card">
                        <span>Månedens overskudd</span>
                        <strong id="metric-surplus">—</strong>
                        <small id="metric-cashflow">Inntekt — · Utgift —</small>
                    </article>
                </div>

                <section class="info-panel">
                    <div class="info-icon">01</div>
                    <div>
                        <h2>Registrer hele balansen samtidig</h2>
                        <p>Når du oppdaterer eiendeler og gjeld, registrerer du alle aktive poster på samme dato. Da blir interne overføringer mellom kontoer ikke feilaktig tolket som endring i formuen.</p>
                    </div>
                    <button class="button primary jump-log" type="button">Loggfør nå</button>
                </section>

                <section class="two-column">
                    <article class="panel">
                        <p class="eyebrow">Måned</p>
                        <h2>Inntekter og utgifter</h2>
                        <div class="cashflow-pair">
                            <div><span>Inntekter</span><strong id="overview-income">—</strong></div>
                            <div><span>Utgifter</span><strong id="overview-expenses">—</strong></div>
                        </div>
                    </article>
                    <article class="panel">
                        <p class="eyebrow">Neste steg</p>
                        <h2>Grafer kommer oppå denne strukturen</h2>
                        <p class="muted">Når historikken begynner å fylles, kan formue, gjeld, overskudd og kategorifordeling visualiseres uten å endre datamodellen.</p>
                    </article>
                </section>
            </section>

            <section id="view-log" class="view">
                <div class="page-heading">
                    <div>
                        <p class="eyebrow">Registrering</p>
                        <h1>Loggfør økonomien</h1>
                    </div>
                </div>

                <article class="panel snapshot-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Formue og gjeld</p>
                            <h2>Økonomisk øyeblikksbilde</h2>
                            <p class="muted">Velg dato og fyll inn alle aktive eiendeler og gjeldsposter.</p>
                        </div>
                        <label class="field date-field">
                            <span>Dato</span>
                            <input id="snapshot-date" type="date">
                        </label>
                    </div>

                    <div id="snapshot-empty" class="empty-state" hidden>
                        Opprett minst én eiendel eller gjeldspost under «Kategorier og poster» først.
                    </div>
                    <div id="snapshot-status" class="snapshot-status"></div>
                    <div id="snapshot-groups"></div>

                    <label class="field">
                        <span>Notat <em>valgfritt</em></span>
                        <input id="snapshot-note" type="text" maxlength="500" placeholder="F.eks. månedlig registrering">
                    </label>
                    <div class="button-row">
                        <button id="save-snapshot-draft" class="button secondary" type="button">Lagre utkast</button>
                        <button id="save-snapshot-complete" class="button primary" type="button">Lagre komplett snapshot</button>
                    </div>
                </article>

                <article class="panel import-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Excel-import</p>
                            <h2>Importer historikk fra regneark</h2>
                            <p class="muted">Første kolonne må være dato eller måned. De øvrige kolonnene tolkes som konkrete eiendeler, gjeldsposter, inntekter eller utgifter.</p>
                        </div>
                    </div>

                    <div class="import-controls">
                        <label class="field">
                            <span>Hva inneholder arket?</span>
                            <select id="import-kind">
                                <option value="asset">Eiendeler</option>
                                <option value="liability">Gjeld</option>
                                <option value="income">Inntekter</option>
                                <option value="expense">Utgifter</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Excel-fil</span>
                            <input id="import-file" type="file" accept=".xlsx,.xls,.csv">
                        </label>
                        <label class="field">
                            <span>Ark</span>
                            <select id="import-sheet" disabled></select>
                        </label>
                    </div>

                    <div id="import-preview" hidden>
                        <div class="import-range">
                            <label class="field">
                                <span>Fra dato</span>
                                <input id="import-from" type="date">
                            </label>
                            <label class="field">
                                <span>Til dato</span>
                                <input id="import-to" type="date">
                            </label>
                            <div class="import-summary" id="import-summary"></div>
                        </div>

                        <div class="import-help">
                            <strong>Kontroller kolonnene før import.</strong>
                            <span>Eksisterende poster gjenbrukes automatisk. Nye poster opprettes i valgt kategori.</span>
                        </div>

                        <div id="import-mapping" class="import-mapping"></div>
                        <div id="import-sample" class="import-sample"></div>

                        <div class="button-row">
                            <button id="import-submit" class="button primary" type="button">Importer valgte data</button>
                        </div>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Kontantstrøm</p>
                            <h2>Ny inntekt eller utgift</h2>
                        </div>
                    </div>
                    <form id="flow-form" class="form-grid">
                        <label class="field">
                            <span>Type</span>
                            <select id="flow-kind">
                                <option value="expense">Utgift</option>
                                <option value="income">Inntekt</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Dato</span>
                            <input id="flow-date" type="date" required>
                        </label>
                        <label class="field span-2">
                            <span>Post</span>
                            <select id="flow-item" required></select>
                        </label>
                        <label class="field">
                            <span>Beløp</span>
                            <div class="money-input"><input id="flow-amount" inputmode="decimal" required placeholder="0,00"><span>kr</span></div>
                        </label>
                        <label class="field">
                            <span>Notat <em>valgfritt</em></span>
                            <input id="flow-note" maxlength="500" placeholder="F.eks. månedskort">
                        </label>
                        <div class="span-2 form-action">
                            <button class="button primary" type="submit">Lagre registrering</button>
                        </div>
                    </form>
                    <p id="flow-item-warning" class="inline-warning" hidden>Opprett først en post for denne typen under «Kategorier og poster».</p>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Historikk</p>
                            <h2>Registreringer denne måneden</h2>
                        </div>
                        <label class="field date-field">
                            <span>Måned</span>
                            <input id="flow-month" type="month">
                        </label>
                    </div>
                    <div id="flow-list" class="entry-list"></div>
                </article>
            </section>

            <section id="view-setup" class="view">
                <div class="page-heading">
                    <div>
                        <p class="eyebrow">Struktur</p>
                        <h1>Kategorier og poster</h1>
                        <p class="muted max-width">En kategori grupperer flere konkrete poster. «Bankkonto» kan for eksempel inneholde «Brukskonto» og «Sparekonto».</p>
                    </div>
                </div>

                <section class="two-column setup-forms">
                    <article class="panel">
                        <h2>Ny kategori</h2>
                        <form id="category-form" class="stack-form">
                            <label class="field">
                                <span>Type</span>
                                <select id="category-kind">
                                    <option value="asset">Eiendel</option>
                                    <option value="liability">Gjeld</option>
                                    <option value="income">Inntekt</option>
                                    <option value="expense">Utgift</option>
                                </select>
                            </label>
                            <label class="field">
                                <span>Navn</span>
                                <input id="category-name" maxlength="120" required placeholder="F.eks. Samleobjekter">
                            </label>
                            <button class="button primary" type="submit">Opprett kategori</button>
                        </form>
                    </article>

                    <article class="panel">
                        <h2>Ny konkret post</h2>
                        <form id="item-form" class="stack-form">
                            <label class="field">
                                <span>Type</span>
                                <select id="item-kind">
                                    <option value="asset">Eiendel</option>
                                    <option value="liability">Gjeld</option>
                                    <option value="income">Inntekt</option>
                                    <option value="expense">Utgift</option>
                                </select>
                            </label>
                            <label class="field">
                                <span>Kategori</span>
                                <select id="item-category" required></select>
                            </label>
                            <label class="field">
                                <span>Navn</span>
                                <input id="item-name" maxlength="160" required placeholder="F.eks. Sparekonto">
                            </label>
                            <button class="button primary" type="submit">Opprett post</button>
                        </form>
                    </article>
                </section>

                <div id="structure-lists" class="structure-grid"></div>
            </section>
        </main>
    </div>
</body>
</html>
