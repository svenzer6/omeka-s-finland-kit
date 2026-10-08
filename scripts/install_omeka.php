<?php
/**
 * Non-interactive Omeka S installation (what the /install web form does).
 *
 * Usage: php scripts/install_omeka.php <omeka_dir>
 *
 * Reads from the environment:
 *   OMEKA_ADMIN_EMAIL, OMEKA_ADMIN_NAME, OMEKA_ADMIN_PASSWORD (required)
 *   OMEKA_TITLE (default "Omeka S Finland Kit"), OMEKA_TIMEZONE
 *   (default Europe/Helsinki), OMEKA_LOCALE (default en_US: the admin
 *   interface does not need to be localised; public sites set their own).
 *
 * Exits 0 without changes when Omeka is already installed.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php install_omeka.php <omeka_dir>\n");
    exit(2);
}
$omekaDir = rtrim($argv[1], '/\\');

$email = getenv('OMEKA_ADMIN_EMAIL');
$name = getenv('OMEKA_ADMIN_NAME') ?: 'Admin';
$password = getenv('OMEKA_ADMIN_PASSWORD');
if (!$email || !$password) {
    fwrite(STDERR, "Set OMEKA_ADMIN_EMAIL and OMEKA_ADMIN_PASSWORD.\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "OMEKA_ADMIN_PASSWORD must have at least 8 characters.\n");
    exit(2);
}

require "$omekaDir/bootstrap.php";
$application = Omeka\Mvc\Application::init(require OMEKA_PATH . '/application/config/application.config.php');
$services = $application->getServiceManager();

if ($services->get('Omeka\Status')->isInstalled()) {
    echo "Omeka S is already installed.\n";
    exit(0);
}

$installer = $services->get('Omeka\Installer');
if (!$installer->preInstall()) {
    fwrite(STDERR, "Pre-install checks failed:\n - " . implode("\n - ", $installer->getErrors()) . "\n");
    exit(1);
}
$installer->registerVars('Omeka\Installation\Task\CreateFirstUserTask', [
    'name' => $name,
    'email' => $email,
    'password-confirm' => ['password' => $password, 'password-confirm' => $password],
]);
$installer->registerVars('Omeka\Installation\Task\AddDefaultSettingsTask', [
    'administrator_email' => $email,
    'installation_title' => getenv('OMEKA_TITLE') ?: 'Omeka S Finland Kit',
    'time_zone' => getenv('OMEKA_TIMEZONE') ?: 'Europe/Helsinki',
    'locale' => getenv('OMEKA_LOCALE') ?: 'en_US',
]);
if (!$installer->install()) {
    fwrite(STDERR, "Installation failed:\n - " . implode("\n - ", $installer->getErrors()) . "\n");
    exit(1);
}
echo "Omeka S installed. Admin: $email\n";
