<?php
/**
 * Create the bilingual demo archive of Omeka S Finland Kit.
 *
 * Usage: php scripts/seed_demo.php <omeka_dir> [--reset]
 *
 * Creates two public sites sharing the same content:
 *   /s/fi  Finnish (locale fi_FI)
 *   /s/en  English (locale en_US)
 * The content is 16 real archive photographs reused from Finna (finna.fi),
 * all Public Domain Mark 1.0 or CC0 1.0, listed in demo/finna.json. Images
 * are downloaded once into files/kit-demo-source and ingested through the
 * File Sideload module. Values are language-tagged and each site filters
 * values by its locale, so one record serves both languages.
 *
 * --reset removes the kit sites, item sets, items, search configs and assets
 * before seeding again. Kit records carry identifiers starting with "KIT-".
 */
$args = array_slice($argv, 1);
$reset = in_array('--reset', $args, true);
$omekaDir = rtrim(current(array_filter($args, fn ($a) => $a[0] !== '-')) ?: '', '/\\');
if (!$omekaDir || !is_file("$omekaDir/bootstrap.php")) {
    fwrite(STDERR, "Usage: php seed_demo.php <omeka_dir> [--reset]\n");
    exit(2);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "The PHP extension gd is required.\n");
    exit(1);
}

$kitDir = dirname(__DIR__);
$content = json_decode(file_get_contents("$kitDir/demo/finna.json"), true);
const BRAND = [78, 0, 142]; // #4E008E
const LANGS = ['fi' => 'fi_FI', 'en' => 'en_US'];
// Omeka compares value languages with the site locale as an IETF tag
// (fi_FI -> fi-FI), so values must use the same tag to pass the filter.
const LANG_TAGS = ['fi' => 'fi-FI', 'en' => 'en-US'];
const SEARCH_SLUGS = ['fi' => 'haku', 'en' => 'search'];
const FINNA_IMAGE = 'https://api.finna.fi/Cover/Show?source=Solr&index=0&size=large&id=';
const FINNA_RECORD = 'https://finna.fi/Record/';

// ------------------------------------------------------------------ fonts
function find_font(array $candidates): string
{
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    fwrite(STDERR, "No TrueType font found (tried: " . implode(', ', $candidates) . ").\n");
    exit(1);
}
$font = find_font(['C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/System/Library/Fonts/Supplemental/Arial.ttf']);
$fontBold = find_font(['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf']);

// ------------------------------------------------------------------ images
function rgb($im, array $c)
{
    return imagecolorallocate($im, $c[0], $c[1], $c[2]);
}

function http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'omeka-s-finland-kit/0.1 (+https://github.com/svenzer6/omeka-s-finland-kit)',
        ]);
        // On Windows (e.g. XAMPP) the bundled CA file is often outdated or
        // misses a local TLS-inspecting proxy: trust the system store.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $body = curl_exec($ch);
        $ok = $body !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        return $ok ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: omeka-s-finland-kit/0.1\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** Grey card used only when Finna cannot be reached. */
function placeholder(string $title, string $font): GdImage
{
    $im = imagecreatetruecolor(1200, 900);
    imagefill($im, 0, 0, rgb($im, [228, 224, 232]));
    imagettftext($im, 34, 0, 60, 460, rgb($im, [70, 60, 80]), $font, $title);
    imagettftext($im, 18, 0, 60, 520, rgb($im, [110, 100, 120]), $font, 'Image unavailable (Finna offline)');
    return $im;
}

function draw_logo(string $fontBold, string $font): GdImage
{
    $w = 640; $h = 160;
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $brand = rgb($im, BRAND);
    $white = rgb($im, [255, 255, 255]);
    imagefilledrectangle($im, 10, 20, 130, 140, $brand);
    imagefilledrectangle($im, 40, 50, 100, 62, $white);
    imagefilledrectangle($im, 40, 74, 100, 86, $white);
    imagefilledrectangle($im, 40, 98, 80, 110, $white);
    imagettftext($im, 46, 0, 160, 95, $brand, $fontBold, 'Demoarkisto');
    imagettftext($im, 18, 0, 163, 132, rgb($im, [70, 70, 80]), $font, 'Omeka S Finland Kit');
    return $im;
}

/** Wide banner cropped from the centre of a photograph. */
function draw_hero(string $source, int $w = 1800, int $h = 640): ?GdImage
{
    $src = @imagecreatefromstring((string) @file_get_contents($source));
    if (!$src) {
        return null;
    }
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh);
    $cw = (int) ($w / $scale); $ch = (int) ($h / $scale);
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) * 0.45), $w, $h, $cw, $ch);
    imagedestroy($src);
    return $out;
}

$imageDir = "$omekaDir/files/kit-demo-source";
@mkdir($imageDir, 0775, true);
$downloaded = 0;
$imageFiles = [];
foreach ($content['items'] as $i => $item) {
    // Downloads are cached; placeholders use another name so that the next
    // run retries the download.
    $name = sprintf('finna-%02d.jpg', $i + 1);
    if (!is_file("$imageDir/$name")) {
        $body = http_get(FINNA_IMAGE . rawurlencode($item['finna']));
        if ($body && @imagecreatefromstring($body)) {
            file_put_contents("$imageDir/$name", $body);
            $downloaded++;
        } else {
            fwrite(STDERR, "warning: could not download {$item['finna']}, using a placeholder\n");
            $name = sprintf('placeholder-%02d.jpg', $i + 1);
            imagejpeg(placeholder($item['fi'][0], $font), "$imageDir/$name", 86);
        }
    }
    $imageFiles[$i] = $name;
}
$logo = draw_logo($fontBold, $font);
imagepng($logo, "$imageDir/kit-logo.png");
$heroIndex = array_search($content['hero'], array_column($content['items'], 'finna'), true) ?: 0;
$hero = draw_hero("$imageDir/" . $imageFiles[$heroIndex]);
if ($hero) {
    imagejpeg($hero, "$imageDir/kit-hero.jpg", 84);
}
echo "images: $downloaded downloaded, " . count($content['items']) . " ready\n";

// ------------------------------------------------------------------ omeka
require "$omekaDir/bootstrap.php";
$application = Omeka\Mvc\Application::init(require OMEKA_PATH . '/application/config/application.config.php');
$services = $application->getServiceManager();
$em = $services->get('Omeka\EntityManager');
$admin = $em->getRepository(Omeka\Entity\User::class)->findOneBy(['role' => 'global_admin'], ['id' => 'ASC']);
if (!$admin) {
    fwrite(STDERR, "No global admin found; install Omeka S first (scripts/install_omeka.php or the web installer).\n");
    exit(1);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($admin);
$api = $services->get('Omeka\ApiManager');
$settings = $services->get('Omeka\Settings');
$siteSettings = $services->get('Omeka\Settings\Site');
$connection = $services->get('Omeka\Connection');

$propIds = [];
$prop = function (string $term) use ($api, &$propIds): int {
    return $propIds[$term] ??= $api->search('properties', ['term' => $term])->getContent()[0]->id();
};
$classId = fn (string $term) => $api->search('resource_classes', ['term' => $term])->getContent()[0]->id();
$val = fn (string $term, string $value, ?string $lang = null) => array_filter([
    'type' => 'literal', 'property_id' => $prop($term), '@value' => $value, '@language' => $lang ? LANG_TAGS[$lang] : null,
]);
$uri = fn (string $term, string $url, string $label) => [
    'type' => 'uri', 'property_id' => $prop($term), '@id' => $url, 'o:label' => $label,
];
$both = fn (string $term, string $fi, string $en) => [$val($term, $fi, 'fi'), $val($term, $en, 'en')];
$kitQuery = ['property' => [['property' => $prop('dcterms:identifier'), 'type' => 'sw', 'text' => 'KIT-']]];

// Existing demo?
if ($api->search('sites', ['slug' => 'fi'])->getContent() && !$reset) {
    fwrite(STDERR, "The kit demo already exists (site /s/fi). Use --reset to recreate it.\n");
    exit(1);
}
if ($reset) {
    foreach (['fi', 'en'] as $slug) {
        foreach ($api->search('sites', ['slug' => $slug])->getContent() as $s) {
            $api->delete('sites', $s->id());
        }
    }
    foreach (['items', 'item_sets'] as $type) {
        $ids = $api->search($type, $kitQuery, ['returnScalar' => 'id'])->getContent();
        if ($ids) {
            $api->batchDelete($type, array_values($ids));
        }
    }
    foreach (SEARCH_SLUGS as $slug) {
        $connection->executeStatement('DELETE FROM search_config WHERE slug = ?', [$slug]);
    }
    foreach ($em->getRepository(Omeka\Entity\Asset::class)->findBy(['name' => ['demoarkisto-logo.png', 'demoarkisto-hero.jpg']]) as $old) {
        @unlink("$omekaDir/files/asset/" . $old->getStorageId() . '.' . $old->getExtension());
        $em->remove($old);
    }
    $em->flush();
    echo "reset done\n";
}

// File Sideload reads the downloaded images from this folder.
$settings->set('file_sideload_directory', realpath($imageDir));
$settings->set('file_sideload_delete_file', 'no');
$settings->set('installation_title', 'Omeka S Finland Kit');
$settings->set('oaipmhrepository_name', 'Demoarkisto (Omeka S Finland Kit)');
$settings->set('oaipmhrepository_namespace_id', 'demoarkisto.example.org');
// Bot Challenge serves a JavaScript check to unknown clients; exempt the
// addresses used for local smoke tests and screenshots.
$exceptionIps = array_filter(array_map('trim', explode(',', getenv('KIT_BOTCHALLENGE_EXCEPTION_IPS') ?: '127.0.0.1,::1')));
$settings->set('botchallenge_exception_ips', array_values($exceptionIps));

// Assets (stored like uploaded assets).
$makeAsset = function (string $source, string $name, string $mediaType, string $ext, string $alt) use ($em, $admin, $omekaDir) {
    $storageId = bin2hex(random_bytes(16));
    @mkdir("$omekaDir/files/asset", 0775, true);
    copy($source, "$omekaDir/files/asset/$storageId.$ext");
    $asset = new Omeka\Entity\Asset();
    $asset->setOwner($admin);
    $asset->setName($name);
    $asset->setMediaType($mediaType);
    $asset->setStorageId($storageId);
    $asset->setExtension($ext);
    $asset->setAltText($alt);
    $em->persist($asset);
    $em->flush();
    return $asset->getId();
};
$logoId = $makeAsset("$imageDir/kit-logo.png", 'demoarkisto-logo.png', 'image/png', 'png', 'Demoarkisto');
$heroId = $hero ? $makeAsset("$imageDir/kit-hero.jpg", 'demoarkisto-hero.jpg', 'image/jpeg', 'jpg', $content['items'][$heroIndex]['fi'][0]) : null;

// Sites.
$sites = [];
foreach (LANGS as $lang => $locale) {
    $sites[$lang] = $api->create('sites', [
        'o:title' => $content['archive'][$lang]['title'],
        'o:slug' => $lang,
        'o:summary' => $content['archive'][$lang]['summary'],
        'o:theme' => 'foundation',
        'o:is_public' => true,
        'o:assign_new_items' => false,
    ])->getContent();
}
echo "sites: " . implode(', ', array_map(fn ($s) => $s->slug(), $sites)) . "\n";

// Item sets.
$setIds = [];
foreach ($content['sets'] as $key => $set) {
    $setIds[$key] = $api->create('item_sets', [
        'dcterms:title' => $both('dcterms:title', $set['fi'][0], $set['en'][0]),
        'dcterms:description' => $both('dcterms:description', $set['fi'][1], $set['en'][1]),
        'dcterms:identifier' => [$val('dcterms:identifier', 'KIT-SET-' . strtoupper($key))],
        'o:resource_class' => ['o:id' => $classId('dctype:Collection')],
        'o:is_public' => true,
    ])->getContent()->id();
}

// Items with media and map points.
$siteRefs = array_map(fn ($s) => ['o:id' => $s->id()], array_values($sites));
$mapped = 0;
foreach ($content['items'] as $i => $item) {
    [$fiTitle, $fiDesc, $fiSubjects] = $item['fi'];
    [$enTitle, $enDesc, $enSubjects] = $item['en'];
    [$rightsLabel, $rightsUrl] = $content['rights'][$item['rights']];
    $orgEn = $content['institutions'][$item['org']] ?? $item['org'];
    $data = [
        'dcterms:title' => $both('dcterms:title', $fiTitle, $enTitle),
        'dcterms:description' => $both('dcterms:description', $fiDesc, $enDesc),
        'dcterms:subject' => array_merge(
            array_map(fn ($s) => $val('dcterms:subject', $s, 'fi'), $fiSubjects),
            array_map(fn ($s) => $val('dcterms:subject', $s, 'en'), $enSubjects)
        ),
        'dcterms:type' => $both('dcterms:type', 'Valokuva', 'Photograph'),
        'dcterms:provenance' => $both('dcterms:provenance', $item['org'], $orgEn),
        'dcterms:rights' => [$uri('dcterms:rights', $rightsUrl, $rightsLabel)],
        'dcterms:source' => [$uri('dcterms:source', FINNA_RECORD . rawurlencode($item['finna']), 'Finna: ' . $item['finna'])],
        'dcterms:identifier' => [$val('dcterms:identifier', sprintf('KIT-%03d', $i + 1)), $val('dcterms:identifier', $item['finna'])],
        'o:resource_class' => ['o:id' => $classId('dctype:StillImage')],
        'o:item_set' => [['o:id' => $setIds[$item['set']]]],
        'o:site' => $siteRefs,
        'o:is_public' => true,
        'o:media' => [[
            'o:ingester' => 'sideload',
            'ingest_filename' => $imageFiles[$i],
            'dcterms:title' => $both('dcterms:title', $fiTitle, $enTitle),
            'dcterms:rights' => [$uri('dcterms:rights', $rightsUrl, $rightsLabel)],
        ]],
    ];
    if ($item['creator'] !== '') {
        $data['dcterms:creator'] = [$val('dcterms:creator', $item['creator'])];
    }
    if ($item['date'] !== '') {
        $data['dcterms:date'] = [$val('dcterms:date', $item['date'])];
    }
    if ($item['place'] !== '') {
        $data['dcterms:spatial'] = [$val('dcterms:spatial', $item['place'])];
    }
    $created = $api->create('items', $data)->getContent();
    if ($item['lat'] !== null) {
        $api->create('mapping_features', [
            'o:item' => ['o:id' => $created->id()],
            'o-module-mapping:geography-type' => 'point',
            'o-module-mapping:geography-coordinates' => [$item['lng'], $item['lat']],
            'o:label' => $item['place'],
        ]);
        $mapped++;
    }
}
echo "items: " . count($content['items']) . " ($mapped on the map)\n";

// One search config per language. Advanced Search stores form labels
// translated at creation time, so each site gets a config built from the
// module's default with labels in its own language.
$searchConfigIds = [];
$engineId = (int) $connection->fetchOne("SELECT id FROM search_engine WHERE adapter = 'internal' ORDER BY id LIMIT 1");
$defaultConfigFile = "$omekaDir/modules/AdvancedSearch/data/configs/search_config.default.php";
if ($engineId && is_file($defaultConfigFile)) {
    foreach (LANGS as $lang => $locale) {
        $translator = new Laminas\I18n\Translator\Translator();
        $translator->setLocale($locale);
        foreach (["$omekaDir/application/language/$locale.mo", "$omekaDir/modules/AdvancedSearch/language/$locale.mo"] as $mo) {
            if (is_file($mo)) {
                $translator->addTranslationFile('gettext', $mo, 'default', $locale);
            }
        }
        $translate = fn ($s) => $translator->translate($s);
        $config = require $defaultConfigFile;
        $connection->executeStatement(
            'INSERT INTO search_config (engine_id, name, slug, form_adapter, settings, created) VALUES (?, ?, ?, ?, ?, NOW())',
            [$engineId, $lang === 'fi' ? 'Haku' : 'Search', SEARCH_SLUGS[$lang], $config['o:form_adapter'], json_encode($config['o:settings'])]
        );
        $searchConfigIds[$lang] = (int) $connection->lastInsertId();
    }
    // Advanced Search checks availability against this cached id => slug map.
    $settings->set('advancedsearch_all_configs', array_map('strval', $connection->fetchAllKeyValue('SELECT id, slug FROM search_config ORDER BY id')));
}

// Pages, navigation and settings per site.
$labels = [
    'fi' => ['home' => 'Etusivu', 'map' => 'Kartta', 'search' => 'Haku', 'about' => 'Tietoa', 'browse' => 'Aineistot',
             'sets' => 'Kokoelmat', 'latest' => 'Poimintoja kokoelmista', 'all' => 'Selaa kaikkia aineistoja',
             'collections' => 'Kokoelmat', 'allSets' => 'Kaikki kokoelmat',
             'other' => 'In English', 'searchTitle' => 'Hae aineistoista'],
    'en' => ['home' => 'Home', 'map' => 'Map', 'search' => 'Search', 'about' => 'About', 'browse' => 'Items',
             'sets' => 'Collections', 'latest' => 'From the collections', 'all' => 'Browse all items',
             'collections' => 'Collections', 'allSets' => 'All collections',
             'other' => 'Suomeksi', 'searchTitle' => 'Search the archive'],
];
$heroItem = $content['items'][$heroIndex];
$heroCaption = [
    'fi' => sprintf('%s %s. Kuva: %s / %s, %s.', $heroItem['fi'][0], $heroItem['date'], $heroItem['creator'], $heroItem['org'], $content['rights'][$heroItem['rights']][0]),
    'en' => sprintf('%s %s. Photo: %s / %s, %s.', $heroItem['en'][0], $heroItem['date'], $heroItem['creator'], $content['institutions'][$heroItem['org']] ?? $heroItem['org'], $content['rights'][$heroItem['rights']][0]),
];
$intro = [
    'fi' => '<div><p>Omeka S Finland Kit · demo</p><h2>Suomalaista arkea ja juhlaa 1900-luvulta</h2><p>Demoarkisto esittelee avoimen lähdekoodin <a href="https://omeka.org/s/">Omeka S</a> -julkaisujärjestelmää suomalaisiin tarpeisiin. Aineistona on 16 vapaasti käytettävää valokuvaa suomalaisista museoista <a href="https://finna.fi">Finnan</a> kautta. Sivusto toimii suomeksi ja englanniksi, kuvat näkyvät kartalla, ja metatiedot ovat haravoitavissa OAI-PMH-rajapinnan kautta.</p></div>',
    'en' => '<div><p>Omeka S Finland Kit · demo</p><h2>Everyday life and celebrations in 20th-century Finland</h2><p>The Demo Archive showcases the open-source <a href="https://omeka.org/s/">Omeka S</a> publishing platform for Finnish needs. It holds 16 freely reusable photographs from Finnish museums, found through <a href="https://finna.fi">Finna</a>. The site works in Finnish and English, photos are shown on a map, and metadata can be harvested through an OAI-PMH endpoint.</p></div>',
];
$orgs = array_unique(array_column($content['items'], 'org'));
$about = [
    'fi' => '<p>Demoarkisto on osa avoimen lähdekoodin <strong>Omeka S Finland Kit</strong> -projektia, joka sisältää asennusskriptit, 33 lisäosan kokoonpanon, lisäosien suomennokset ja Docker-ympäristön.</p>'
        . '<h3>Aineistot ja oikeudet</h3><p>Valokuvat ovat peräisin seuraavien organisaatioiden kokoelmista Finnan kautta. Ne on merkitty joko <a href="https://creativecommons.org/publicdomain/mark/1.0/">Public Domain Mark 1.0</a> - tai <a href="https://creativecommons.org/publicdomain/zero/1.0/">CC0 1.0</a> -merkinnällä, joten niitä saa käyttää vapaasti. Jokaisessa aineistossa on linkki alkuperäiseen Finna-tietueeseen.</p><ul>'
        . implode('', array_map(fn ($o) => '<li>' . htmlspecialchars($o) . '</li>', $orgs)) . '</ul>'
        . '<p>Suomenkieliset nimekkeet ja kuvaukset ovat Finnasta, englanninkieliset käännökset ja karttapisteet (likimääräiset) on lisätty demoa varten.</p>',
    'en' => '<p>The Demo Archive is part of the open-source <strong>Omeka S Finland Kit</strong> project, which includes install scripts, a 33-module configuration, Finnish translations of modules and a Docker environment.</p>'
        . '<h3>Content and rights</h3><p>The photographs come from the collections of the following organisations through Finna. They are marked <a href="https://creativecommons.org/publicdomain/mark/1.0/">Public Domain Mark 1.0</a> or <a href="https://creativecommons.org/publicdomain/zero/1.0/">CC0 1.0</a> and can be reused freely. Every item links to its original Finna record.</p><ul>'
        . implode('', array_map(fn ($o) => '<li>' . htmlspecialchars($content['institutions'][$o] ?? $o) . '</li>', $orgs)) . '</ul>'
        . '<p>Finnish titles and descriptions come from Finna; English translations and approximate map points were added for the demo.</p>',
];
$css = <<<'CSS'
/* Omeka S Finland Kit – brand overrides for the Foundation theme */
:root { --kit-brand: #4E008E; --kit-brand-dark: #3a006b; --kit-ink: #1f1a24; }
body, button, input, select, textarea { font-family: "Open Sans", Arial, Helvetica, sans-serif; color: var(--kit-ink); }
a, a:visited { color: var(--kit-brand); }
a:hover, a:focus { color: var(--kit-brand-dark); }
header, .top-bar, .top-bar ul { background-color: #fff; }
header { border-bottom: 4px solid var(--kit-brand); }
.site-title img { max-height: 64px; width: auto; }
.top-bar .menu a, .dropdown.menu > li > a { color: var(--kit-brand); font-weight: 600; }
.menu .active > a, .menu .is-active > a, .dropdown.menu > li.active > a { background: var(--kit-brand) !important; color: #fff !important; }
.button, a.button, button.button, input[type="submit"], .pagination .current, .label, .badge { background-color: var(--kit-brand) !important; color: #fff; }
.button:hover, a.button:hover, button.button:hover, input[type="submit"]:hover { background-color: var(--kit-brand-dark) !important; }
.button.disabled, .button[disabled] { background-color: #b9a3cc !important; }
.site-page-pagination a, .pagination a { background-color: var(--kit-brand) !important; color: #fff !important; }
h1, h2, h3, .page-title { color: #2a0050; }
footer { background: var(--kit-brand); color: #fff; padding: 1.5rem; }
footer a { color: #fff; text-decoration: underline; }
/* Home: full-width hero with an overlapping intro card */
.block-asset .assets { margin: 0 -50vw; position: relative; left: 50%; right: 50%; width: 100vw; max-width: 100vw; margin-left: -50vw; margin-right: -50vw; }
.block-asset .asset { margin: 0; }
.block-asset .asset img { width: 100%; height: clamp(260px, 42vw, 560px); object-fit: cover; display: block; filter: contrast(1.05); }
.block-asset .caption { text-align: right; font-size: .8rem; color: #6b6474; padding: .35rem 1rem 0; }
/* The intro is the html block right after the hero (HTMLPurifier strips classes). */
.block-asset + .block-html > div { position: relative; z-index: 2; background: #fff; max-width: 760px; margin: -150px 0 2.5rem; padding: 2rem 2.25rem; border-top: 6px solid var(--kit-brand); box-shadow: 0 18px 50px -28px rgba(40,0,80,.55); }
.block-asset + .block-html h2 { font-size: clamp(1.6rem, 3vw, 2.3rem); line-height: 1.15; margin: .2rem 0 .8rem; }
.block-asset + .block-html > div > p:first-child { text-transform: uppercase; letter-spacing: .08em; font-size: .8rem; font-weight: 700; color: var(--kit-brand); margin: 0; }
@media (max-width: 640px) { .block-asset + .block-html > div { margin-top: -60px; padding: 1.25rem; } }
/* Uniform thumbnails in browse grids */
.resource-grid .resource img, .resource-list .resource img, .preview-block .resource img, .browse-preview .resource img, .item-set .thumbnail img {
  width: 100%; aspect-ratio: 4 / 3; object-fit: cover; }
CSS;

foreach ($sites as $lang => $site) {
    $l = $labels[$lang];
    $other = $lang === 'fi' ? 'en' : 'fi';
    $newPage = fn (string $title, string $slug, array $blocks) => $api->create('site_pages', [
        'o:site' => ['o:id' => $site->id()], 'o:title' => $title, 'o:slug' => $slug, 'o:block' => $blocks,
    ])->getContent();

    $homeBlocks = [];
    if ($heroId) {
        // The core asset block stores its attachments as a plain list.
        $homeBlocks[] = ['o:layout' => 'asset', 'o:data' => [[
            'id' => (string) $heroId, 'page' => '', 'alt_link_title' => '', 'caption' => $heroCaption[$lang],
        ]]];
    }
    $homeBlocks[] = ['o:layout' => 'html', 'o:data' => ['html' => $intro[$lang]]];
    $homeBlocks[] = ['o:layout' => 'browsePreview', 'o:data' => [
        'resource_type' => 'item_sets', 'query' => 'sort_by=id&sort_order=asc', 'heading' => $l['collections'], 'limit' => 4,
        'components' => ['resource-heading', 'resource-body', 'thumbnail'], 'link-text' => $l['allSets'],
    ]];
    $homeBlocks[] = ['o:layout' => 'browsePreview', 'o:data' => [
        'resource_type' => 'items', 'query' => 'sort_by=random', 'heading' => $l['latest'], 'limit' => 8,
        'components' => ['resource-heading', 'thumbnail'], 'link-text' => $l['all'],
    ]];
    $home = $newPage($l['home'], $lang === 'fi' ? 'etusivu' : 'home', $homeBlocks);
    $map = $newPage($l['map'], $lang === 'fi' ? 'kartta' : 'map', [
        ['o:layout' => 'pageTitle', 'o:data' => []],
        ['o:layout' => 'mappingMapQuery', 'o:data' => ['query' => '']],
    ]);
    $search = $newPage($l['search'], $lang === 'fi' ? 'haku' : 'search', [
        ['o:layout' => 'pageTitle', 'o:data' => []],
        ['o:layout' => 'searchingForm', 'o:data' => ['search_config' => (string) ($searchConfigIds[$lang] ?? ''), 'display_results' => true, 'heading' => $l['searchTitle']]],
    ]);
    $aboutPage = $newPage($l['about'], $lang === 'fi' ? 'tietoa' : 'about', [
        ['o:layout' => 'pageTitle', 'o:data' => []],
        ['o:layout' => 'html', 'o:data' => ['html' => $about[$lang]]],
    ]);

    $api->update('sites', $site->id(), [
        'o:homepage' => ['o:id' => $home->id()],
        'o:item_pool' => ['item_set_id' => array_values($setIds)],
        'o:site_item_set' => array_map(fn ($id) => ['o:item_set' => ['o:id' => $id]], array_values($setIds)),
        'o:navigation' => [
            ['type' => 'page', 'data' => ['label' => '', 'id' => $home->id()], 'links' => []],
            ['type' => 'browse', 'data' => ['label' => $l['browse'], 'query' => ''], 'links' => []],
            ['type' => 'browseItemSets', 'data' => ['label' => $l['sets'], 'query' => ''], 'links' => []],
            ['type' => 'page', 'data' => ['label' => '', 'id' => $map->id()], 'links' => []],
            ['type' => 'page', 'data' => ['label' => '', 'id' => $search->id()], 'links' => []],
            ['type' => 'page', 'data' => ['label' => '', 'id' => $aboutPage->id()], 'links' => []],
            ['type' => 'url', 'data' => ['label' => $l['other'], 'url' => "../$other"], 'links' => []],
        ],
    ], [], ['isPartial' => true]);

    $siteSettings->setTargetId($site->id());
    $siteSettings->set('locale', LANGS[$lang]);
    $siteSettings->set('filter_locale_values', true);
    $siteSettings->set('show_locale_label', false);
    $siteSettings->set('css_editor_css', $css);
    $siteSettings->set('theme_settings_foundation', [
        'stylesheet' => 'default',
        'nav_layout' => 'dropdown',
        'logo' => $logoId,
        'footer' => $lang === 'fi'
            ? '<p>Demoarkisto · Omeka S Finland Kit · Valokuvat: suomalaiset museot Finnan kautta (Public Domain Mark / CC0).</p>'
            : '<p>Demo Archive · Omeka S Finland Kit · Photographs: Finnish museums via Finna (Public Domain Mark / CC0).</p>',
        'browse_layout' => 'grid',
        'show_layout' => 'stack',
        'media_display' => 'embed',
    ]);
    if (!empty($searchConfigIds[$lang])) {
        $siteSettings->set('advancedsearch_main_config', $searchConfigIds[$lang]);
        $siteSettings->set('advancedsearch_configs', [$searchConfigIds[$lang]]);
        $siteSettings->set('advancedsearch_main_config_replace_quick', true);
    }
}
echo "pages and settings ok\n";
echo "Open: /s/fi and /s/en\n";
