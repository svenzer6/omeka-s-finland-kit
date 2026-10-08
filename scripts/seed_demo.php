<?php
/**
 * Create the bilingual demo archive of Omeka S Finland Kit.
 *
 * Usage: php scripts/seed_demo.php <omeka_dir> [--reset]
 *
 * Creates two public sites sharing the same content:
 *   /s/fi  Finnish (locale fi_FI)
 *   /s/en  English (locale en_US)
 * Values are language-tagged and each site filters values by its locale, so
 * one record serves both languages. Images are generated with GD (no
 * downloads) and ingested through the File Sideload module. All records are
 * fictional and carry identifiers starting with "KIT-".
 *
 * --reset removes the kit sites, item sets and items before seeding again.
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
$content = json_decode(file_get_contents("$kitDir/demo/content.json"), true);
const BRAND = [78, 0, 142]; // #4E008E
const LANGS = ['fi' => 'fi_FI', 'en' => 'en_US'];
// Omeka compares value languages with the site locale as an IETF tag
// (fi_FI -> fi-FI), so values must use the same tag to pass the filter.
const LANG_TAGS = ['fi' => 'fi-FI', 'en' => 'en-US'];
const SEARCH_SLUGS = ['fi' => 'haku', 'en' => 'search'];

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
$fontSerif = find_font(['C:/Windows/Fonts/georgia.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf', '/usr/share/fonts/dejavu/DejaVuSerif.ttf', '/System/Library/Fonts/Supplemental/Georgia.ttf', $font]);

// ------------------------------------------------------------------ images
function rgb($im, array $c, int $alpha = 0)
{
    return imagecolorallocatealpha($im, $c[0], $c[1], $c[2], $alpha);
}

function wrap_text(string $text, string $fontFile, float $size, int $maxWidth): array
{
    $lines = [];
    $line = '';
    foreach (explode(' ', $text) as $word) {
        $try = $line === '' ? $word : "$line $word";
        $box = imagettfbbox($size, 0, $fontFile, $try);
        if ($box[2] - $box[0] > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    $lines[] = $line;
    return $lines;
}

function add_grain($im, int $w, int $h, int $amount): void
{
    for ($i = 0; $i < $w * $h / 18; $i++) {
        $v = mt_rand(0, 255);
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocatealpha($im, $v, $v, $v, 127 - $amount));
    }
}

function add_vignette($im, int $w, int $h): void
{
    for ($i = 0; $i < 60; $i++) {
        imagerectangle($im, $i, $i, $w - 1 - $i, $h - 1 - $i, imagecolorallocatealpha($im, 20, 12, 4, 60 + $i));
    }
}

/** Sepia photograph with a simple scene chosen by $variant. */
function draw_photo(int $variant, string $caption, string $font): GdImage
{
    $w = 1200; $h = 900;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $c = $t < 0.55 ? [(int) (222 - 50 * $t), (int) (200 - 50 * $t), (int) (160 - 50 * $t)] : [(int) (150 - 60 * $t), (int) (122 - 50 * $t), (int) (88 - 40 * $t)];
        imageline($im, 0, $y, $w, $y, rgb($im, $c));
    }
    $dark = rgb($im, [58, 42, 26], 20);
    $mid = rgb($im, [96, 74, 50], 30);
    switch ($variant % 4) {
        case 0: // factory: hall, chimneys, gate
            imagefilledrectangle($im, 120, 380, 1080, 640, $mid);
            foreach ([260, 520, 860] as $x) {
                imagefilledrectangle($im, $x, 150, $x + 50, 400, $dark);
                imagefilledellipse($im, $x + 40, 130, 120, 60, rgb($im, [180, 165, 140], 70));
            }
            for ($x = 150; $x < 1060; $x += 70) {
                imagefilledrectangle($im, $x, 430, $x + 40, 500, rgb($im, [210, 190, 150], 40));
            }
            imagefilledrectangle($im, 500, 520, 700, 640, $dark);
            for ($i = 0; $i < 14; $i++) {
                $px = 140 + $i * 68 + mt_rand(-10, 10);
                imagefilledellipse($im, $px, 690, 22, 22, $dark);
                imagefilledrectangle($im, $px - 10, 700, $px + 10, 760, $dark);
            }
            break;
        case 1: // lake: trees, water, pavilion
            for ($x = 0; $x < $w; $x += 46) {
                $th = mt_rand(120, 220);
                imagefilledpolygon($im, [$x, 520, $x + 23, 520 - $th, $x + 46, 520], $dark);
            }
            imagefilledrectangle($im, 0, 520, $w, $h, rgb($im, [160, 140, 110], 20));
            for ($y = 540; $y < $h; $y += 14) {
                imageline($im, mt_rand(0, 300), $y, mt_rand(700, $w), $y, rgb($im, [210, 195, 165], 60));
            }
            imagefilledpolygon($im, [780, 470, 900, 400, 1020, 470], $dark);
            imagefilledrectangle($im, 800, 470, 1000, 520, $mid);
            break;
        case 2: // harbour: boat, mast, water
            imagefilledrectangle($im, 0, 560, $w, $h, rgb($im, [140, 120, 92], 15));
            imagefilledpolygon($im, [300, 560, 900, 560, 820, 650, 380, 650], $dark);
            imagefilledrectangle($im, 590, 250, 606, 560, $dark);
            imagefilledpolygon($im, [610, 270, 610, 520, 820, 520], rgb($im, [230, 215, 185], 30));
            imagefilledrectangle($im, 0, 470, 250, 560, $mid);
            for ($y = 670; $y < $h; $y += 16) {
                imageline($im, mt_rand(0, 400), $y, mt_rand(600, $w), $y, rgb($im, [200, 185, 150], 70));
            }
            break;
        default: // street: buildings, tram, snow
            for ($x = 0; $x < $w; $x += 200) {
                $bh = mt_rand(280, 420);
                imagefilledrectangle($im, $x, 620 - $bh, $x + 190, 620, $mid);
                for ($wy = 640 - $bh; $wy < 580; $wy += 60) {
                    for ($wx = $x + 20; $wx < $x + 170; $wx += 50) {
                        imagefilledrectangle($im, $wx, $wy, $wx + 25, $wy + 35, rgb($im, [225, 205, 160], 40));
                    }
                }
            }
            imagefilledrectangle($im, 330, 520, 870, 700, $dark);
            for ($wx = 360; $wx < 840; $wx += 80) {
                imagefilledrectangle($im, $wx, 545, $wx + 55, 600, rgb($im, [215, 195, 150], 30));
            }
            imageline($im, 600, 520, 600, 380, $dark);
            imageline($im, 0, 380, $w, 380, $dark);
            for ($i = 0; $i < 900; $i++) {
                imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), 5, 5, rgb($im, [245, 240, 230], 40));
            }
    }
    add_grain($im, $w, $h, 35);
    add_vignette($im, $w, $h);
    // White print border with caption.
    $out = imagecreatetruecolor($w + 80, $h + 150);
    imagefill($out, 0, 0, rgb($out, [246, 242, 233]));
    imagecopy($out, $im, 40, 40, 0, 0, $w, $h);
    imagettftext($out, 22, 0, 44, $h + 105, rgb($out, [70, 60, 50]), $font, $caption);
    imagedestroy($im);
    return $out;
}

/** Typed transcript page with a cassette in the corner. */
function draw_transcript(string $title, string $year, string $font, string $fontSerif): GdImage
{
    $w = 1000; $h = 1300;
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, rgb($im, [244, 238, 222]));
    $ink = rgb($im, [40, 38, 36]);
    imagettftext($im, 20, 0, 80, 120, $ink, $fontSerif, 'LITTEROINTI / TRANSCRIPT');
    imagettftext($im, 30, 0, 80, 180, $ink, $fontSerif, $title);
    imagettftext($im, 20, 0, 80, 225, rgb($im, [90, 85, 80]), $fontSerif, $year);
    imageline($im, 80, 255, $w - 80, 255, $ink);
    $y = 320;
    while ($y < $h - 140) {
        $speaker = mt_rand(0, 2) ? 'V:' : 'H:';
        imagettftext($im, 20, 0, 80, $y, $ink, $fontSerif, $speaker);
        $lines = mt_rand(1, 4);
        for ($i = 0; $i < $lines && $y < $h - 140; $i++) {
            $len = $i === $lines - 1 ? mt_rand(200, 700) : mt_rand(650, 780);
            for ($x = 140; $x < 140 + $len; $x += mt_rand(40, 110)) {
                $wl = mt_rand(25, 90);
                imagefilledrectangle($im, $x, $y - 14, min($x + $wl, 140 + $len), $y - 4, rgb($im, [70, 66, 62], 40));
            }
            $y += 34;
        }
        $y += 20;
    }
    // Cassette.
    imagefilledrectangle($im, 720, 70, 920, 200, rgb($im, BRAND));
    imagefilledrectangle($im, 740, 90, 900, 140, rgb($im, [240, 232, 210]));
    imagefilledellipse($im, 780, 170, 34, 34, rgb($im, [240, 232, 210]));
    imagefilledellipse($im, 860, 170, 34, 34, rgb($im, [240, 232, 210]));
    imagettftext($im, 14, 0, 80, $h - 70, rgb($im, [120, 110, 100]), $font, 'DEMO · Omeka S Finland Kit');
    add_grain($im, $w, $h, 18);
    return $im;
}

/** Aged handwritten document or letter. */
function draw_document(string $title, string $year, string $font, string $fontSerif): GdImage
{
    $w = 1000; $h = 1300;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = abs($y - $h / 2) / ($h / 2);
        imageline($im, 0, $y, $w, $y, rgb($im, [(int) (236 - 30 * $t), (int) (224 - 34 * $t), (int) (190 - 40 * $t)]));
    }
    $ink = rgb($im, [36, 40, 82]);
    imagettftext($im, 30, 0, 90, 150, $ink, $fontSerif, $title);
    imagettftext($im, 22, 0, $w - 200, 150, $ink, $fontSerif, $year);
    imageellipse($im, $w - 170, 260, 150, 150, rgb($im, [150, 40, 40], 30));
    imageellipse($im, $w - 170, 260, 130, 130, rgb($im, [150, 40, 40], 30));
    for ($y = 300; $y < $h - 160; $y += 46) {
        imageline($im, 90, $y + 8, $w - 90, $y + 8, rgb($im, [170, 160, 140], 80));
        $x = 90;
        $end = $w - 90 - mt_rand(0, 300);
        while ($x < $end) {
            $wl = mt_rand(30, 110);
            $prev = null;
            for ($px = $x; $px < min($x + $wl, $end); $px += 3) {
                $py = $y + (int) (sin($px / 5) * 5 + mt_rand(-2, 2));
                if ($prev) {
                    imageline($im, $prev[0], $prev[1], $px, $py, $ink);
                }
                $prev = [$px, $py];
            }
            $x += $wl + mt_rand(14, 24);
        }
    }
    imagettftext($im, 14, 0, 90, $h - 70, rgb($im, [110, 100, 90]), $font, 'DEMO · Omeka S Finland Kit');
    add_grain($im, $w, $h, 22);
    return $im;
}

/** Bold two-colour poster. */
function draw_poster(int $variant, string $title, string $year, string $fontBold, string $font): GdImage
{
    $w = 900; $h = 1300;
    $palettes = [[[232, 74, 39], [28, 32, 60], [245, 225, 180]], [[38, 110, 160], [250, 200, 60], [245, 240, 225]]];
    [$bg, $fg, $paper] = $palettes[$variant % 2];
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, rgb($im, $paper));
    imagefilledrectangle($im, 40, 40, $w - 40, 860, rgb($im, $bg));
    if ($variant % 2) {
        for ($i = 0; $i < 7; $i++) {
            imagefilledrectangle($im, 90 + $i * 100, 820 - $i * 90, 160 + $i * 100, 820, rgb($im, $fg));
        }
    } else {
        imagefilledellipse($im, 450, 420, 560, 560, rgb($im, $fg));
        imagefilledellipse($im, 450, 420, 320, 320, rgb($im, $bg));
        imagefilledellipse($im, 450, 420, 120, 120, rgb($im, $paper));
    }
    $ink = rgb($im, [28, 28, 32]);
    $y = 960;
    foreach (wrap_text(mb_strtoupper($title, 'UTF-8'), $fontBold, 46, $w - 140) as $line) {
        imagettftext($im, 46, 0, 70, $y, $ink, $fontBold, $line);
        $y += 66;
    }
    imagettftext($im, 34, 0, 70, $y + 20, rgb($im, $bg), $fontBold, $year);
    imagettftext($im, 14, 0, 70, $h - 50, rgb($im, [110, 100, 90]), $font, 'DEMO · Omeka S Finland Kit');
    add_grain($im, $w, $h, 14);
    return $im;
}

function draw_logo(string $fontBold, string $font): GdImage
{
    $w = 640; $h = 160;
    $im = imagecreatetruecolor($w, $h);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $brand = rgb($im, BRAND);
    imagefilledrectangle($im, 10, 20, 130, 140, $brand);
    imagefilledrectangle($im, 40, 50, 100, 62, rgb($im, [255, 255, 255]));
    imagefilledrectangle($im, 40, 74, 100, 86, rgb($im, [255, 255, 255]));
    imagefilledrectangle($im, 40, 98, 80, 110, rgb($im, [255, 255, 255]));
    imagettftext($im, 46, 0, 160, 95, $brand, $fontBold, 'Demoarkisto');
    imagettftext($im, 18, 0, 163, 132, rgb($im, [70, 70, 80]), $font, 'Omeka S Finland Kit');
    return $im;
}

$imageDir = "$omekaDir/files/kit-demo-source";
@mkdir($imageDir, 0775, true);
mt_srand(2026);
foreach ($content['items'] as $i => $item) {
    $style = $content['sets'][$item['set']]['style'];
    $fiTitle = $item['fi'][0];
    $caption = sprintf('DEMO · %s · %s, %s', $fiTitle, $item['place'], $item['date']);
    switch ($style) {
        case 'photo': $im = draw_photo($i, $caption, $font); break;
        case 'interview': $im = draw_transcript($fiTitle, $item['date'], $font, $fontSerif); break;
        case 'document': $im = draw_document($fiTitle, $item['date'], $font, $fontSerif); break;
        default: $im = draw_poster($i, $fiTitle, $item['date'], $fontBold, $font);
    }
    imagejpeg($im, sprintf('%s/kit-%02d.jpg', $imageDir, $i + 1), 86);
    imagedestroy($im);
}
$logo = draw_logo($fontBold, $font);
imagepng($logo, "$imageDir/kit-logo.png");
imagedestroy($logo);
echo "images: " . count($content['items']) . " + logo\n";

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

$propIds = [];
$prop = function (string $term) use ($api, &$propIds): int {
    return $propIds[$term] ??= $api->search('properties', ['term' => $term])->getContent()[0]->id();
};
$classId = fn (string $term) => $api->search('resource_classes', ['term' => $term])->getContent()[0]->id();
$val = fn (string $term, string $value, ?string $lang = null) => array_filter([
    'type' => 'literal', 'property_id' => $prop($term), '@value' => $value, '@language' => $lang ? LANG_TAGS[$lang] : null,
]);
$kitQuery = ['property' => [['property' => $prop('dcterms:identifier'), 'type' => 'sw', 'text' => 'KIT-']]];

// Existing demo?
$existing = $api->search('sites', ['slug' => 'fi'])->getContent();
if ($existing && !$reset) {
    fwrite(STDERR, "The kit demo already exists (site /s/fi). Use --reset to recreate it.\n");
    exit(1);
}
$connection = $services->get('Omeka\Connection');
if ($reset) {
    foreach (['fi', 'en'] as $slug) {
        foreach ($api->search('sites', ['slug' => $slug])->getContent() as $s) {
            $api->delete('sites', $s->id());
        }
    }
    foreach (SEARCH_SLUGS as $slug) {
        $connection->executeStatement('DELETE FROM search_config WHERE slug = ?', [$slug]);
    }
    foreach ($em->getRepository(Omeka\Entity\Asset::class)->findBy(['name' => 'demoarkisto-logo.png']) as $oldLogo) {
        @unlink("$omekaDir/files/asset/" . $oldLogo->getStorageId() . '.' . $oldLogo->getExtension());
        $em->remove($oldLogo);
    }
    $em->flush();
    foreach (['items', 'item_sets'] as $type) {
        $ids = $api->search($type, $kitQuery, ['returnScalar' => 'id'])->getContent();
        if ($ids) {
            $api->batchDelete($type, array_values($ids));
        }
    }
    echo "reset done\n";
}

// File Sideload reads the generated images from this folder.
$settings->set('file_sideload_directory', realpath($imageDir));
$settings->set('file_sideload_delete_file', 'no');
$settings->set('installation_title', 'Omeka S Finland Kit');
$settings->set('oaipmhrepository_name', 'Demoarkisto (Omeka S Finland Kit)');
$settings->set('oaipmhrepository_namespace_id', 'demoarkisto.example.org');
// Bot Challenge serves a JavaScript check to unknown clients; exempt the
// addresses used for local smoke tests and screenshots.
$exceptionIps = array_filter(array_map('trim', explode(',', getenv('KIT_BOTCHALLENGE_EXCEPTION_IPS') ?: '127.0.0.1,::1')));
$settings->set('botchallenge_exception_ips', array_values($exceptionIps));

// Logo asset (stored like an uploaded asset).
$storageId = bin2hex(random_bytes(16));
@mkdir("$omekaDir/files/asset", 0775, true);
copy("$imageDir/kit-logo.png", "$omekaDir/files/asset/$storageId.png");
$asset = new Omeka\Entity\Asset();
$asset->setOwner($admin);
$asset->setName('demoarkisto-logo.png');
$asset->setMediaType('image/png');
$asset->setStorageId($storageId);
$asset->setExtension('png');
$asset->setAltText('Demoarkisto');
$em->persist($asset);
$em->flush();

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
        'dcterms:title' => [$val('dcterms:title', $set['fi'][0], 'fi'), $val('dcterms:title', $set['en'][0], 'en')],
        'dcterms:description' => [$val('dcterms:description', $set['fi'][1], 'fi'), $val('dcterms:description', $set['en'][1], 'en')],
        'dcterms:identifier' => [$val('dcterms:identifier', 'KIT-SET-' . strtoupper($key))],
        'o:resource_class' => ['o:id' => $classId('dctype:Collection')],
        'o:is_public' => true,
    ])->getContent()->id();
}

// Items with media and map points.
$siteRefs = array_map(fn ($s) => ['o:id' => $s->id()], array_values($sites));
foreach ($content['items'] as $i => $item) {
    [$fiTitle, $fiDesc, $fiSubjects, $fiType] = $item['fi'];
    [$enTitle, $enDesc, $enSubjects, $enType] = $item['en'];
    $data = [
        'dcterms:title' => [$val('dcterms:title', $fiTitle, 'fi'), $val('dcterms:title', $enTitle, 'en')],
        'dcterms:description' => [
            $val('dcterms:description', "$fiDesc (Kuvitteellinen demoaineisto.)", 'fi'),
            $val('dcterms:description', "$enDesc (Fictional demo record.)", 'en'),
        ],
        'dcterms:creator' => [$val('dcterms:creator', $item['creator'])],
        'dcterms:date' => [$val('dcterms:date', $item['date'])],
        'dcterms:subject' => array_merge(
            array_map(fn ($s) => $val('dcterms:subject', $s, 'fi'), $fiSubjects),
            array_map(fn ($s) => $val('dcterms:subject', $s, 'en'), $enSubjects)
        ),
        'dcterms:type' => [$val('dcterms:type', $fiType, 'fi'), $val('dcterms:type', $enType, 'en')],
        'dcterms:spatial' => [$val('dcterms:spatial', $item['place'])],
        'dcterms:language' => [$val('dcterms:language', 'fin')],
        'dcterms:rights' => [
            $val('dcterms:rights', 'CC BY 4.0 – demoaineisto', 'fi'),
            $val('dcterms:rights', 'CC BY 4.0 – demo content', 'en'),
        ],
        'dcterms:identifier' => [$val('dcterms:identifier', sprintf('KIT-%03d', $i + 1))],
        'o:resource_class' => ['o:id' => $classId($item['class'])],
        'o:item_set' => [['o:id' => $setIds[$item['set']]]],
        'o:site' => $siteRefs,
        'o:is_public' => true,
        'o:media' => [[
            'o:ingester' => 'sideload',
            'ingest_filename' => sprintf('kit-%02d.jpg', $i + 1),
            'dcterms:title' => [$val('dcterms:title', $fiTitle, 'fi'), $val('dcterms:title', $enTitle, 'en')],
        ]],
    ];
    $created = $api->create('items', $data)->getContent();
    $api->create('mapping_features', [
        'o:item' => ['o:id' => $created->id()],
        'o-module-mapping:geography-type' => 'point',
        'o-module-mapping:geography-coordinates' => [$item['lng'], $item['lat']],
        'o:label' => $item['place'],
    ]);
}
echo "items: " . count($content['items']) . "\n";

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
             'sets' => 'Kokoelmat', 'latest' => 'Uusimmat aineistot', 'all' => 'Selaa kaikkia aineistoja',
             'other' => 'In English', 'mapTitle' => 'Aineistot kartalla', 'searchTitle' => 'Hae aineistoista'],
    'en' => ['home' => 'Home', 'map' => 'Map', 'search' => 'Search', 'about' => 'About', 'browse' => 'Items',
             'sets' => 'Collections', 'latest' => 'Latest items', 'all' => 'Browse all items',
             'other' => 'Suomeksi', 'mapTitle' => 'Items on a map', 'searchTitle' => 'Search the archive'],
];
$intro = [
    'fi' => '<h2>Tervetuloa Demoarkistoon</h2><p>Tämä on <strong>kuvitteellinen</strong> kulttuuri- ja tutkimusarkisto, joka esittelee avoimen lähdekoodin <a href="https://omeka.org/s/">Omeka S</a> -julkaisujärjestelmää suomalaisiin tarpeisiin. Sivusto toimii suomeksi ja englanniksi, aineistot näkyvät kartalla, ja metatiedot ovat haravoitavissa OAI-PMH-rajapinnan kautta.</p>',
    'en' => '<h2>Welcome to the Demo Archive</h2><p>This is a <strong>fictional</strong> cultural and research archive that showcases the open-source <a href="https://omeka.org/s/">Omeka S</a> publishing platform for Finnish needs. The site works in Finnish and English, items are shown on a map, and metadata can be harvested through an OAI-PMH endpoint.</p>',
];
$about = [
    'fi' => '<p>Demoarkisto on osa <strong>Omeka S Finland Kit</strong> -projektia: avoimen lähdekoodin kokonaisuus, joka sisältää asennusskriptit, 32 lisäosan kokoonpanon, lisäosien suomennokset ja Docker-ympäristön.</p><ul><li>Kaikki aineistot ja kuvat ovat kuvitteellisia demoaineistoja.</li><li>Käyttöliittymä: suomi ja englanti.</li><li>Metatiedot: Dublin Core, kielitunnisteet fi/en.</li></ul>',
    'en' => '<p>The Demo Archive is part of the <strong>Omeka S Finland Kit</strong> project: an open-source bundle with install scripts, a 32-module configuration, Finnish translations of modules and a Docker environment.</p><ul><li>All records and images are fictional demo content.</li><li>Interface: Finnish and English.</li><li>Metadata: Dublin Core, language-tagged fi/en.</li></ul>',
];
$css = <<<'CSS'
/* Omeka S Finland Kit – brand overrides for the Foundation theme */
:root { --kit-brand: #4E008E; --kit-brand-dark: #3a006b; }
body, button, input, select, textarea { font-family: "Open Sans", Arial, Helvetica, sans-serif; }
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
CSS;

foreach ($sites as $lang => $site) {
    $l = $labels[$lang];
    $other = $lang === 'fi' ? 'en' : 'fi';
    $newPage = fn (string $title, string $slug, array $blocks) => $api->create('site_pages', [
        'o:site' => ['o:id' => $site->id()], 'o:title' => $title, 'o:slug' => $slug, 'o:block' => $blocks,
    ])->getContent();

    $home = $newPage($l['home'], $lang === 'fi' ? 'etusivu' : 'home', [
        ['o:layout' => 'html', 'o:data' => ['html' => $intro[$lang]]],
        ['o:layout' => 'browsePreview', 'o:data' => [
            'resource_type' => 'items', 'query' => '', 'heading' => $l['latest'], 'limit' => 6,
            'components' => ['resource-heading', 'resource-body', 'thumbnail'], 'link-text' => $l['all'],
        ]],
    ]);
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
        'logo' => $asset->getId(),
        'footer' => $lang === 'fi'
            ? '<p>Demoarkisto · Omeka S Finland Kit · Kaikki aineistot ovat kuvitteellisia.</p>'
            : '<p>Demo Archive · Omeka S Finland Kit · All records are fictional.</p>',
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
