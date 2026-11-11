# Changelog

## 0.5.0 · 2026-11-11

* Pro: integrazione con WPML. I testi di ogni pagina Nucleo vanno a WPML come pacchetto di stringhe; quando la traduzione è pronta Nucleo compila la pagina tradotta, con HTML, CSS e runtime della sua lingua.
* Pro: layout, sezioni, menu, logo e campi collegati seguono la lingua della pagina. Tipi, tassonomie e campi del modello di contenuto si dichiarano a WPML da soli.
* Pro: i link interni portano alla traduzione della pagina collegata nei tre modi di URL di WPML; un cambio di slug o di modo ricompila le pagine interessate.
* Pro: se cambia un testo dell'originale, WPML segna la traduzione da aggiornare e la pagina tradotta mantiene la traduzione precedente; i cambi di sola struttura arrivano alle traduzioni da soli.
* Pro: blocco Selettore di lingua (nc/language-switcher) per WPML e Polylang.
* Pro: ability nucleo/wpml-check e regole wpml.* nel controllo di qualità del sito: modo dell'integrazione, versione di WPML, traduzioni non ricostruite o non aggiornate.
* Da sapere: integrazione provata con WPML 5.3.0; con una versione successiva il controllo di qualità mostra un avviso.

## 0.4.4 · 2026-11-10

* Sicurezza: endpoint OAuth, moduli nativi e campi personalizzati leggono solo le chiavi attese e sanificano ogni valore per tipo.
* Sicurezza: il titolo del documento passa da kses per gli utenti senza unfiltered_html.
* Sicurezza: i valori CSS di stili, style.custom e token devono seguire una grammatica chiusa: numeri con unità, colori, parole chiave, nomi tra virgolette e un elenco fisso di funzioni CSS.
* Da sapere: un nc/embed generico accetta solo i provider oEmbed noti a WordPress. Il filtro nucleo/embed_hosts aggiunge altri host.
* Migliorato: l'avviso dei conflitti con la cache ha il link Chiudi e resta chiuso finché i conflitti non cambiano.
* Migliorato: ogni file PHP controlla ABSPATH; titoli dei blocchi ed etichette delle condizioni sono traducibili.
* Migliorato: il widget di Friendly Captcha passa alla versione 1.1.3.
* Nuovo: nel Pro il pannello Condizioni di un popup salva trigger e frequenza; layout-conditions-set accetta il campo popup.
* Da sapere: popup, condizione di lingua, guida WooCommerce, critical-request e component-activate escono dal Free del sito: senza i moduli Pro non avevano effetto. Restano nel Pro.
* Corretto: la guida per gli agenti del Free non rimanda più a kit-list e ai blocchi woo/*, assenti nel pacchetto.
* Da sapere: nel Free doc-create, doc-patch e layout-conditions-set rifiutano una condizione language nuova o modificata, perché il Free non rileva la lingua; import e push la tolgono con un avviso. Le condizioni già salvate restano.
* Da sapere: nel Pro senza licenza settings-set non salva URL e chiave del servizio di critical CSS (codice license_required) e salva le altre impostazioni della chiamata.

## 0.4.3 · 2026-11-10

* Da sapere: style.custom è una lista di regole strutturate; Nucleo costruisce il selettore da elenchi chiusi (stato, elemento interno, posizione, pseudo-elemento). I documenti nel formato precedente si convertono al caricamento.
* Da sapere: critical-set usa il CSS ricevuto come elenco di selettori e salva le regole corrispondenti dei fogli di stile locali; le altre si scartano.
* Sicurezza: icone SVG, anteprima e meta SEO passano da wp_kses o esc_* nel punto in cui si stampano; il JSON-LD usa wp_json_encode.

## 0.4.2 · 2026-11-10

* Migliorato: le descrizioni dei tool MCP indicano quando usarli e quale tool usare al loro posto.

## 0.4.1 · 2026-11-09

* Nuovo: I campi repeater e relation e i gruppi di campi con posizione su una tassonomia funzionano anche in Free.
* Da sapere: Lo store degli artefatti passa da wp-content/cache/nucleo a uploads/nucleo/store; l'aggiornamento sposta la cartella del sito.
* Da sapere: I transient e il cookie dei codici OTP e delle raccolte usano il prefisso nucleo_ al posto di nc_.
* Migliorato: Gli script dei captcha, lo stile del menu e lo stile dell'anteprima si caricano con wp_enqueue_script e wp_enqueue_style.
* Migliorato: Il pacchetto per WordPress.org non contiene funzioni bloccate, codice libero, attivazione di plugin da remoto né file di traduzione.

## 0.4.0 · 2026-11-05

* Nuovo: Pagina prodotto, archivio, carrello e checkout di WooCommerce governati da Nucleo, senza woocommerce.css né gli script jQuery classici.
* Nuovo: Carrello componibile di Nucleo e checkout di WooCommerce configurabile con woo-checkout-parts-set.
* Nuovo: Il blocco mini-carrello ha un pannello configurabile e una variante drawer laterale, con articoli in card, coupon e riepilogo.
* Nuovo: Pagina prodotto con schede Reviews e Additional information e modulo recensioni nello stile di Nucleo, anche se il tema non dichiara il supporto di WooCommerce.
* Da sapere: doc-create con kind layout senza postType crea un documento nucleo_layout; con postType page o post rifiuta i kind layout, popup e section.
* Da sapere: Via MCP un permesso insufficiente (insufficient_scope) arriva come JSON con lo scope mancante, come gli altri errori.
* Migliorato: La CLI stampa errori, avvisi e output in inglese, e nucleo component new genera descrizioni, commenti ed esempi in inglese.
* Migliorato: Le abilità WooCommerce non compaiono quando WooCommerce non è attivo.
* Migliorato: woo-checkout-parts-set imposta azienda, telefono e indirizzo 2 anche con il checkout classico; la lettura non conta come scrittura.
* Migliorato: doc-validate e quality-check accettano postType e controllano un contenuto nuovo con i layout del suo tipo.
* Migliorato: Descrizioni dei tool MCP completate con codici d'errore e rimandi tra tool; nuovo topic woocommerce di catalog-get e regola R4.7 della guida.
* Migliorato: Dopo un aggiornamento di Nucleo gli URL degli script portano la nuova versione e i documenti si ricompilano.
* Corretto: Nel pacchetto Free con WooCommerce attivo «Aggiungi al carrello» non produce più un errore fatale.
* Corretto: Un elemento letto con content-list o content-get si può reinviare a content-upsert così com'è; content-get restituisce l'hash da usare come base.
* Corretto: content-upsert e terms-set non riscrivono gli elementi identici a quelli salvati: rispondono unchanged e post_modified resta com'è. Il push della CLI stampa a parte i contenuti invariati (content unchanged).
* Corretto: Un push della CLI interrotto a metà non segnala più come conflitto le modifiche già accettate dal sito; il conflitto su content/ e terms/ suggerisce il comando giusto.
* Corretto: layout-explain segue il runtime: su un prodotto segna single come sostituita da product e riporta in applied i layout stampati.
* Corretto: doc-create assegna anche alle bozze uno slug unico ricavato dal titolo; lo slug di un documento esportato si normalizza invece di essere rifiutato.
* Corretto: Una pagina Carrello o Checkout con un documento Nucleo mantiene il blocco di WooCommerce, e woo-checkout-parts-set la modifica senza perderlo.
* Corretto: Le varianti dei prodotti mostrano anche i termini senza ordine salvato.
* Corretto: L'URL di uno script con un parametro come ?server= riceve la versione.

## 0.3.5 · 2026-11-03

* Corretto: Con più video, form, embed o slider nella stessa pagina, ogni istanza carica il proprio script al primo uso, non solo la prima.
* Corretto: Con un layout single, form, collezioni e campi collegati della pagina si mostrano correttamente.
* Corretto: Una pagina Nucleo con problemi di qualità bloccanti non va online neanche dall'editor di WordPress.
* Corretto: Con un token OAuth, modificare una pagina già online richiede lo scope di pubblicazione.
* Corretto: Il push della CLI valida tutta la cartella prima di modificare il sito ed esce con errore quando il sito rifiuta l'import.
* Corretto: Il pull della CLI conserva una copia dei file locali sovrascritti e segnala le pagine cancellate dal sito.
* Corretto: Titolo e stato cambiati dal backend non vengono più sovrascritti in silenzio dal push; wp.status draft riporta in bozza una pagina pubblicata.
* Corretto: La coda di ricompilazione non perde i documenti che falliscono e li mostra in status.
* Corretto: Il pulsante Publish dell'editor salva anche i testi della vista testi; l'editor chiede conferma prima di uscire con modifiche non salvate.
* Corretto: Il mini-cart funziona con WordPress in una sottocartella.

## 0.3.4 · 2026-11-03

* Migliorato: La richiesta di più blocchi al catalogo restituisce una forma compatta e resta sotto 25.000 caratteri. Gli agenti AI leggono i blocchi di una pagina in una sola chiamata.
* Corretto: Il controllo qualità non segnala più la mancanza del landmark nav quando il menu sta nell'header del sito.
* Corretto: La validazione di una pagina non ancora salvata considera header e footer del sito, come il salvataggio.

## 0.3.3 · 2026-11-02

* Migliorato: I link dell'amministrazione verso nucleowp.dev usano i nuovi indirizzi del sito: documentazione, prezzi, area clienti e feedback.
* Corretto: Il link di confronto dei piani apre la sezione prezzi della pagina Pro. Il link di rinnovo apre le licenze nell'area clienti.
* Corretto: Il link al feedback apre la bacheca nella lingua dell'utente.

## 0.3.2 · 2026-11-02

* Corretto: Con WordPress in inglese, l'interfaccia di plugin, editor, WP-CLI e gli script dei blocchi era in parte in italiano. Ora segue la lingua di WordPress, in inglese e in italiano.
* Corretto: Le etichette scritte nelle pagine, come slider, video, embed e menu, usano la lingua del sito, non quella di chi salva. Con Polylang o WPML usano la lingua della pagina.
* Corretto: Le date dell'amministrazione usano la lingua dell'utente.
* Da sapere: Le pagine salvate prima di questa versione si correggono al prossimo salvataggio o con la ricompilazione da Strumenti.
* Migliorato: La documentazione del sito è completa in italiano e in inglese.

## 0.3.1 · 2026-11-01

* Da sapere: Il tema si chiama Nucleo Canvas (cartella nucleo-canvas). I siti con nucleo-theme ricevono Nucleo Canvas come aggiornamento, e WordPress lo attiva al posto del vecchio tema.
* Migliorato: Nucleo Canvas riprende logo, posizioni dei menu e opzioni del vecchio tema. Dopo l'aggiornamento puoi eliminare nucleo-theme.
* Nuovo: Il plugin Free scaricato da nucleowp.dev si aggiorna dal server updates.nucleowp.dev. Il Free di WordPress.org si aggiorna dalla directory.
* Nuovo: Il tema aggiunge commenti, paginazione e i template delle voci, e funziona anche senza il plugin.

## 0.3.0 · 2026-11-01

* Nuovo: Aggiunge la pagina Assistenza con il link al modulo di feedback e i dati del sito.
* Migliorato: La pagina Agenti contiene le guide per collegare Claude, ChatGPT, Cursor, VS Code, Codex, Gemini CLI, Windsurf e STDIO.
* Nuovo: Aggiunge la scheda Impostazioni pagina nell'editor Nucleo, con tema, layout e titolo per pagina.
* Nuovo: Il pulsante Torna all'editor di WordPress conserva il documento Nucleo; Modifica con Nucleo lo ripristina.
* Nuovo: Aggiunge i blocchi Embed, Off-canvas, HTML e Shortcode.
* Migliorato: Mostra l'inserter con icone e permette di trascinare i blocchi nell'anteprima.
* Nuovo: Modifica titoli, testi, pulsanti e liste direttamente nell'anteprima con un doppio clic.
* Nuovo: Aggiunge controlli di qualità su immagini, dimensione del DOM, CSS, JS, font ed embed.
* Sicurezza: Limita i token Bearer alle rotte REST di Nucleo e irrobustisce il server OAuth.
* Da sapere: I token Bearer funzionano solo su /nucleo/v1/*. Aggiorna i client che usano altri percorsi.

## 0.2.0 · 2026-10-30

* Pro: Nucleo Pro e il tema si aggiornano dal server updates.nucleowp.dev.
* Pro: Il Pro riceve gli aggiornamenti con una licenza attiva sul sito.
* Da sapere: Il tema si aggiorna senza licenza, solo dopo il plugin: richiede almeno la versione di Nucleo con cui esce.
* Da sapere: Il dominio di licenze, aggiornamenti e negozio passa a nucleowp.dev.

## 0.1.12 · 2026-10-30

* Nuovo: Aggiunge il captcha ai moduli nativi nc/native-form.
* Nuovo: Ogni sito usa un solo provider: reCAPTCHA v2, reCAPTCHA v3, hCaptcha, Cloudflare Turnstile o Friendly Captcha.
* Nuovo: Le chiavi si impostano in settings-set, gruppo captcha, o nella scheda Captcha delle impostazioni.
* Sicurezza: Il segreto è in sola scrittura.
* Nuovo: Ogni modulo nativo mostra il widget sopra i pulsanti.
* Nuovo: Con settings.captcha impostato a false, il modulo non mostra il widget.
* Sicurezza: Il server verifica il token all'invio e all'invio del codice OTP.
* Nuovo: Se il provider non risponde, il modulo accetta l'invio.

## 0.1.11 · 2026-10-30

* Corretto: Nucleo legge i titoli di tutte le pagine di risultati di doc-list, non solo della prima.
* Corretto: Le pagine oltre la prima pagina di risultati ricevono ora il titolo corretto.
* Corretto: I titoli SEO di tutte le pagine del sito sono ora completi.

## 0.1.10 · 2026-10-29

* Nuovo: Nuova chiave siteIcon: imposta la favicon del sito con un'immagine della libreria media.
* Nuovo: WordPress usa la stessa immagine come icona delle app.
* Nuovo: Il valore null rimuove la favicon.
* Nuovo: Con l'URL di un altro sito, l'immagine si cerca per nome del file.
* Nuovo: L'esportazione include siteIcon solo se il sito ha una favicon.

## 0.1.9 · 2026-10-29

* Migliorato: Il CSS critico include la regola che nasconde gli elementi chiusi, come l'icona di chiusura del menu e i pannelli chiusi.
* Corretto: L'anteprima non fa più cambiare altezza all'header.
* Migliorato: Il caricatore segna i fogli di stile in caricamento e il controllo li attende.
* Migliorato: Su server lenti il controllo parte solo dopo il CSS completo.
* Migliorato: Il controllo del CSS critico ignora l'altezza degli elementi sotto la prima schermata.

## 0.1.8 · 2026-10-29

* Nuovo: Con l'opzione del sito imageFormats, spenta di default, Nucleo genera le versioni AVIF e WebP accanto a ogni immagine caricata.
* Nuovo: Le immagini usano il formato moderno e mostrano l'originale se il browser non lo supporta.
* Nuovo: Il blocco immagine e le immagini dei post usano il tag picture con l'immagine originale come fallback.
* Nuovo: Puoi disattivare i formati per un singolo blocco immagine.
* Nuovo: Il blocco immagine accetta sorgenti scritte a mano.
* Nuovo: Le sorgenti escludono i ritagli. Senza dimensioni, il blocco immagine segue la regola di larghezza di WordPress.
* Nuovo: Lo strumento MCP nucleo-media-formats genera i formati per le immagini già caricate.
* Nuovo: Il tag picture non altera layout e classi dell'immagine.

## 0.1.7 · 2026-10-29

* Nuovo: Ogni blocco dichiara le sue parti interne, come etichette, legende e segnaposto.
* Nuovo: Le parti accettano le chiavi di stile, con breakpoint e stati.
* Nuovo: style.custom aggiunge CSS al nodo per le regole che le chiavi non coprono.
* Nuovo: I gruppi size, spacing, typography, color, border e motion valgono per ogni blocco. supports.styleExclude li esclude.
* Migliorato: L'unità segue la proprietà CSS. transition accetta ogni proprietà e più transizioni.
* Migliorato: Una pagina genera un solo grafo JSON-LD nel footer. I dati dell'azienda accettano qualsiasi proprietà schema.org.
* Nuovo: Due strumenti MCP nuovi, nucleo-gap-report e nucleo-gap-list, registrano ciò che a Nucleo manca.
* Nuovo: Nuove chiavi di stile: accentColor, fontStyle, minWidth, maxHeight e transform.
* Migliorato: Il filtro della sitemap si attiva solo nelle richieste della sitemap. L'export omette azienda e icone vuote.
* Corretto: Il nuovo test nel browser delle parti ha trovato due difetti, corretti: l'indice dichiarava una parte che non esiste, e la numerazione del modulo vinceva sullo stile della parte.

## 0.1.6 · 2026-10-29

* Corretto: optionalWeight e numberWeight restano numeri: 400 non diventa 400px.
* Nuovo: numberFont imposta la famiglia del font ($font.mono).
* Nuovo: numberFontSize e numberWeight impostano dimensione e peso della numerazione.

## 0.1.5 · 2026-10-29

* Nuovo: Il blocco nc/breadcrumbs stampa la BreadcrumbList del suo percorso.
* Nuovo: I dati dell'azienda accettano legalName, vatID e taxID.
* Migliorato: transition accetta filter, background, border e text-decoration-color.
* Migliorato: nc/native-form accetta la larghezza con maxWidth o width.
* Nuovo: nc/native-form aggiunge la numerazione letters-box e numbers-box.
* Migliorato: nc/native-form accetta gli stili optionalSize, optionalWeight, number*, controlMinHeight, placeholderColor, checkSize, checkAccent e navJustify.
* Corretto: La linea di legendLine ora si vede e arriva fino al margine.
* Migliorato: nc/button accetta gli stili di dimensione, ad esempio width 100%.

## 0.1.4 · 2026-10-29

* Nuovo: Aggiunge gli stati di stile Hover e Current.
* Nuovo: Aggiunge il blocco indice nc/toc, che evidenzia la voce della sezione visibile durante lo scroll.
* Nuovo: Aggiunge segni e stili ai campi del blocco modulo nativo.
* Nuovo: Importa icone del sito da una cartella di SVG con il comando nucleo icons import.
* Nuovo: Scarica icone da Iconify una sola volta con il comando nucleo icons add.
* Nuovo: Elenca e rimuove le icone del sito con i comandi nucleo icons list e nucleo icons remove.
* Nuovo: Aggiunge e rimuove singole icone del sito tramite lo strumento MCP dedicato. Il set accetta fino a 300 icone.
* Nuovo: Un'icona del sito con lo stesso nome di un'icona di Nucleo la sostituisce in tutto il sito.
* Nuovo: Genera dati strutturati per azienda, schema SEO nei metadati e breadcrumb automatici.
* Nuovo: Esclude dalla sitemap le pagine con noindex.
* Nuovo: Aggiunge la regola R4.6 per gli agenti: niente mu-plugin, codice nel tema o script in wp_head; il PHP del sito va nei componenti.

## 0.1.3 · 2026-10-28

* Nuovo: La build scrive automaticamente la voce del changelog a ogni nuova versione.
* Nuovo: Il changelog usa i commit dall'ultima versione.
* Nuovo: Se la CLI claude manca o fallisce, la build mostra un avviso e prosegue.

## 0.1.2 · 2026-10-28

* Nuovo: Gli stili supportano bordi su un lato, anche tratteggiati, whiteSpace, filtro in scala di grigi e opacità sui contenitori.
* Migliorato: Le card con link diventano cliccabili per intero.
* Nuovo: Le sezioni si nascondono se il parametro è vuoto.
* Nuovo: Le sezioni supportano il parametro list e il binding repeat.
* Nuovo: Le icone includono auto, autobus, trattore, lente, contagiri, livelli e rotazione.
* Migliorato: L'accordion mostra un'icona chevron o plus e il testo eredita la tipografia del blocco.
* Nuovo: Il modulo ha gruppi di stile per controlli e pulsanti.
* Nuovo: I campi del modulo occupano metà larghezza e si raggruppano in fieldset.
* Nuovo: I campi non validi mostrano un bordo evidenziato.
* Migliorato: Tutte le 1797 stringhe sono tradotte.

## 0.1.1 · 2026-10-28

* Migliorato: Il validatore controlla array, media: e menu: e propone la correzione con l'id del nodo.
* Migliorato: Il push mostra i valori ammessi nell'errore e recupera dalla libreria i media mancanti.
* Migliorato: Il push segnala la pagina che nomina una sezione non importata.
* Migliorato: Le email dei moduli per l'amministratore includono il destinatario.
* Migliorato: Gli errori dei moduli mostrano la causa all'amministratore e allo sviluppatore.
* Nuovo: I blocchi aggiungono 8 icone e supportano la trasformazione del testo.
* Corretto: Il pulsante link non mostra più la sottolineatura.
* Nuovo: Nucleo genera titolo, descrizione, Open Graph e noindex. Con Yoast o Rank Math attivi, il SEO di Nucleo si spegne.
* Migliorato: Le voci di menu nuove compaiono subito dopo il push.
* Migliorato: Le traduzioni sono rigenerate: 1793 stringhe, tutte tradotte.
