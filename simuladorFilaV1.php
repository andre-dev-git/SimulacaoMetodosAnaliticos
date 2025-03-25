<?php

class QueueSimulator {
    private $servers;
    private $capacity;
    private $arrivalRange;
    private $serviceRange;
    private $randomNumbers;
    private $randIndex = 0;
    private $time = 0;
    private $queue = [];
    private $stateDurations = [];
    private $losses = 0;

    public function __construct($servers, $capacity, $arrivalRange, $serviceRange, $randomNumbers) {
        $this->servers = $servers;
        $this->capacity = $capacity;
        $this->arrivalRange = $arrivalRange;
        $this->serviceRange = $serviceRange;
        $this->randomNumbers = $randomNumbers;
    }

    private function nextRandom() {
        $rnd = $this->randomNumbers[$this->randIndex];
        $this->randIndex++;
        return $rnd;
    }

    private function getTime($range) {
        return $range[0] + ($range[1] - $range[0]) * $this->nextRandom();
    }

    public function simulate() {
        $this->time = 0;
        $arrivalTime = 1.0;
        $serviceTimes = [];
        $serversBusy = 0;

        while ($this->randIndex < count($this->randomNumbers)) {
            $nextService = count($serviceTimes) > 0 ? min($serviceTimes) : PHP_INT_MAX;
            $nextEvent = min($arrivalTime, $nextService);

            $duration = $nextEvent - $this->time;
            $state = count($this->queue);
            if (!isset($this->stateDurations[$state])) {
                $this->stateDurations[$state] = 0;
            }
            $this->stateDurations[$state] += $duration;

            $this->time = $nextEvent;

            if ($nextEvent == $arrivalTime) {
                if (count($this->queue) < $this->capacity) {
                    $this->queue[] = $this->time;
                    if ($serversBusy < $this->servers) {
                        $serviceTimes[] = $this->time + $this->getTime($this->serviceRange);
                        $serversBusy++;
                    }
                } else {
                    $this->losses++;
                }
                if ($this->randIndex < count($this->randomNumbers)) {
                    $arrivalTime = $this->time + $this->getTime($this->arrivalRange);
                }
            } else {
                array_shift($this->queue);
                $serversBusy--;
                array_shift($serviceTimes);
                if (count($this->queue) >= $serversBusy + 1 && $serversBusy < $this->servers && $this->randIndex < count($this->randomNumbers)) {
                    $serviceTimes[] = $this->time + $this->getTime($this->serviceRange);
                    $serversBusy++;
                }
            }
        }
    }

    public function stateReport() {
        $totalTime = array_sum($this->stateDurations);
        $percentages = [];
        foreach ($this->stateDurations as $state => $duration) {
            $percentages[$state] = [
                'time' => round($duration, 4),
                'percentage' => round(($duration / $totalTime) * 100, 2)
            ];
        }
        ksort($percentages);

        return [
            'states' => $percentages,
            'losses' => $this->losses,
            'total_time' => round($totalTime, 4)
        ];
    }
}

$options = getopt("", ["servers:", "capacity:", "arrival_min:", "arrival_max:", "service_min:", "service_max:", "random:"]);

if (!isset($options['servers'], $options['capacity'], $options['arrival_min'], $options['arrival_max'], $options['service_min'], $options['service_max'])) {
    exit("Usage: php simulator.php --servers=NUM --capacity=NUM --arrival_min=NUM --arrival_max=NUM --service_min=NUM --service_max=NUM --random=NUM1,NUM2,...\n");
}

$servers = (int)$options['servers'];
$capacity = (int)$options['capacity'];
$arrivalRange = [(float)$options['arrival_min'], (float)$options['arrival_max']];
$serviceRange = [(float)$options['service_min'], (float)$options['service_max']];
if(isset($options['random'])){
    $randomNumbers = array_map('floatval', explode(',', $options['random']));
} else {
    $randomNumbers = [];
    for ($i = 0; $i < 100000; $i++) {
        $randomNumbers[] = mt_rand() / mt_getrandmax(); 
    }
}

$simulator = new QueueSimulator($servers, $capacity, $arrivalRange, $serviceRange, $randomNumbers);
$simulator->simulate();

echo "===== RELATÓRIO DA SIMULAÇÃO =====\n\n";

$report = $simulator->stateReport();

echo "Estado | Tempo acumulado | Probabilidade\n";
foreach ($report['states'] as $state => $data) {
    echo str_pad($state, 6, ' ', STR_PAD_BOTH) . " | " . 
         str_pad(number_format($data['time'], 4), 15, ' ', STR_PAD_BOTH) . " | " .
         str_pad(number_format($data['percentage'], 2)."%", 14, ' ', STR_PAD_BOTH) . "\n";
}

echo "\nNúmero de clientes perdidos: {$report['losses']}\n";
echo "Tempo global da simulação: {$report['total_time']} minutos\n";
