<?php
declare(strict_types=1);

function run_python_json(string $pythonPath, string $scriptPath, array $payload, int $timeoutSeconds = 30): array
{
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Forecast script was not found.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open([$pythonPath, $scriptPath], $descriptors, $pipes, dirname($scriptPath));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the Python forecast engine.');
    }

    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $exitCode = null;
    $deadline = microtime(true) + max(1, $timeoutSeconds);

    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                    fclose($pipes[$pipeIndex]);
                }
            }
            proc_close($process);
            throw new RuntimeException('Python forecast engine timed out.');
        }

        usleep(50000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) {
        $exitCode = (int) $closeCode;
    }

    if ($exitCode !== 0) {
        $message = trim($stderr) !== '' ? trim($stderr) : 'Python forecast engine exited with an error.';
        throw new RuntimeException($message);
    }

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Python forecast engine returned invalid JSON.');
    }

    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Python forecast engine rejected the request.'));
    }

    return $decoded;
}
