<?php

use App\Console\Commands\ManageRightsScopes;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// Synthetic-only privacy probe: this process has an empty in-memory database,
// so it cannot create a real scope or inspect persisted credentials.
require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance('env', 'testing');
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', ':memory:');
$app['config']->set('database.connections.sqlite.url', null);
QuestionHelper::disableStty();

$command = $app->make(ManageRightsScopes::class);
$command->setLaravel($app);
$input = new ArrayInput([
    'action' => 'register', '--actor-id' => '1',
    '--scope' => 'synthetic-review-scope', '--reference' => 'SYNTHETIC-NONSECRET-REFERENCE',
], $command->getDefinition());
$input->setInteractive(true);
exit($command->run($input, new ConsoleOutput));
