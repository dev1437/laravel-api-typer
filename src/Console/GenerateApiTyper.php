<?php

namespace Dev1437\LaravelApiTyper\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class GenerateApiTyper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'typer:generate {--output=resources/js/api-returns.d.ts : Output file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate API return types from your laravel controllers';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '512M');

        $this->info('Analyzing controllers...');

        $types = $this->getReturnTypes();
        // dd($types);

        return Command::SUCCESS;
    }

    private function getReturnTypes()
    {
        // Clear previous collected data
        $outputFile = sys_get_temp_dir().'/phpstan-controller-types.json';

        // Clear the file
        file_put_contents($outputFile, json_encode([]));

        // Run PHPStan via command line
        $process = new Process([
            base_path('vendor/bin/phpstan'),
            'analyse',
            '--configuration='.__DIR__.'/../phpstan.neon',
            '--error-format=table',
            '--no-progress',
            '--memory-limit=512M',
            '--debug',
            app_path('Http/Controllers'),
        ]);

        $process->run(function ($type, $buffer) {
            // You can output PHPStan's output if needed
            $this->line($buffer);
        });

        // PHPStan may return non-zero even if analysis works (just has errors)
        // So we check if our collector has data
        $types = json_decode(file_get_contents($outputFile), true) ?: [];

        return $types;
        // if (empty($types)) {
        //     $this->error('No controller return types found.');
        //     return 1;
        // }
    }

    private function displayResults(array $types): void
    {
        $this->table(
            ['Class', 'Method', 'Return Type', 'Explicit?'],
            collect($types)->flatMap(function ($methods, $class) {
                return collect($methods)->map(function ($data, $method) use ($class) {
                    return [
                        $class,
                        $method,
                        $data['return_type'],
                        $data['has_explicit_return_type'] ?? 'N/A',
                    ];
                });
            })->toArray()
        );
    }
}
