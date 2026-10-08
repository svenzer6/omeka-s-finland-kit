<?php
/**
 * Install and activate every module listed in modules.json.
 *
 * Usage: php scripts/install_modules.php <omeka_dir>
 *
 * Modules are retried in passes so that dependencies (e.g. Common, Guest)
 * get installed before the modules that need them, whatever their order in
 * the manifest. Runs as the first global admin user.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php install_modules.php <omeka_dir>\n");
    exit(2);
}
$omekaDir = rtrim($argv[1], '/\\');
$manifest = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents(__DIR__ . '/../modules.json')), true);

require $omekaDir . '/bootstrap.php';
$application = Omeka\Mvc\Application::init(require OMEKA_PATH . '/application/config/application.config.php');
$services = $application->getServiceManager();
$em = $services->get('Omeka\EntityManager');

$admin = $em->getRepository(Omeka\Entity\User::class)->findOneBy(['role' => 'global_admin'], ['id' => 'ASC']);
if (!$admin) {
    fwrite(STDERR, "No global admin found; finish the web installer first.\n");
    exit(1);
}
$services->get('Omeka\AuthenticationService')->getStorage()->write($admin);

$moduleManager = $services->get('Omeka\ModuleManager');
$pending = array_column($manifest['modules'], 'name');
$requires = array_column($manifest['modules'], 'requires', 'name');
$installedCount = 0;
$errors = [];

// A module is only attempted when its dependencies were already active when
// this process booted: some modules create tables before checking their
// dependencies, and a failed attempt would leave them half installed.
$activeAtStart = [];
foreach ($moduleManager->getModules() as $id => $module) {
    if ($module->getState() === Omeka\Module\Manager::STATE_ACTIVE) {
        $activeAtStart[$id] = true;
    }
}

for ($pass = 1; $pending && $pass <= 5; $pass++) {
    $progress = false;
    foreach ($pending as $i => $name) {
        $module = $moduleManager->getModule($name);
        if (!$module) {
            $errors[$name] = 'not found in modules/';
            unset($pending[$i]);
            continue;
        }
        switch ($module->getState()) {
            case Omeka\Module\Manager::STATE_ACTIVE:
                echo "active   $name\n";
                unset($pending[$i]);
                $progress = true;
                continue 2;
            case Omeka\Module\Manager::STATE_NOT_ACTIVE:
                $moduleManager->activate($module);
                echo "enabled  $name\n";
                unset($pending[$i]);
                $progress = true;
                continue 2;
            case Omeka\Module\Manager::STATE_NEEDS_UPGRADE:
                $moduleManager->upgrade($module);
                $moduleManager->activate($module);
                echo "upgraded $name\n";
                unset($pending[$i]);
                $progress = true;
                continue 2;
            case Omeka\Module\Manager::STATE_NOT_INSTALLED:
                $missing = array_diff($requires[$name] ?? [], array_keys($activeAtStart));
                if ($missing) {
                    $errors[$name] = 'waiting for ' . implode(', ', $missing);
                    continue 2;
                }
                try {
                    $moduleManager->install($module);
                    echo "install  $name\n";
                    $installedCount++;
                    unset($pending[$i]);
                    $progress = true;
                } catch (\Throwable $e) {
                    // Probably a missing dependency; retry next pass.
                    $errors[$name] = $e->getMessage();
                    $em->clear();
                    $services->get('Omeka\AuthenticationService')->getStorage()->write(
                        $em->find(Omeka\Entity\User::class, $admin->getId())
                    );
                }
                continue 2;
            default:
                $errors[$name] = 'state: ' . $module->getState();
                unset($pending[$i]);
        }
    }
    if (!$progress) {
        break;
    }
}

// Modules installed in this process (e.g. Common) only register their
// services on the next bootstrap, so dependants may need a fresh process.
$attempt = (int) (getenv('KIT_INSTALL_ATTEMPT') ?: 1);
if ($pending && $installedCount > 0 && $attempt < 4) {
    echo "-- restarting to load the services of newly installed modules\n";
    putenv('KIT_INSTALL_ATTEMPT=' . ($attempt + 1));
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($omekaDir), $code);
    exit($code);
}

foreach ($pending as $name) {
    echo "FAILED   $name: " . ($errors[$name] ?? 'unknown') . "\n";
}
foreach ($errors as $name => $msg) {
    if (!in_array($name, $pending, true) && $moduleManager->getModule($name)
        && $moduleManager->getModule($name)->getState() !== Omeka\Module\Manager::STATE_ACTIVE) {
        echo "FAILED   $name: $msg\n";
    }
}
exit($pending ? 1 : 0);
