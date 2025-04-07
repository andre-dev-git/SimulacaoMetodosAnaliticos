<?php

class DynamicTandemQueueSimulator {
    private $queues;
    private $arrivalRange;
    private $randomNumbers;
    private $randIndex = 0;
    private $time = 0;
    private $losses = 0;

    public function __construct($queues, $arrivalRange, $randomNumbers) {
        $this->queues = $queues;
        foreach ($this->queues as &$queue) {
            $queue['queue'] = [];
            $queue['stateDurations'] = [];
            $queue['serviceTimes'] = [];
            $queue['serversBusy'] = 0;
        }
        $this->arrivalRange = $arrivalRange;
        $this->randomNumbers = $randomNumbers;
    }

    private function nextRandom() {
        return $this->randomNumbers[$this->randIndex++ % count($this->randomNumbers)];
    }

    private function getTime($range) {
        return $range[0] + ($range[1] - $range[0]) * $this->nextRandom();
    }

    public function simulate() {
        $arrivalTime = 1.0;

        while ($this->randIndex < count($this->randomNumbers)) {
            $nextEvents = [$arrivalTime];
            foreach ($this->queues as &$q) {
                if (!empty($q['serviceTimes'])) {
                    $nextEvents[] = min($q['serviceTimes']);
                }
            }

            $nextEvent = min($nextEvents);
            $duration = $nextEvent - $this->time;

            foreach ($this->queues as &$q) {
                $state = count($q['queue']);
                $q['stateDurations'][$state] = ($q['stateDurations'][$state] ?? 0) + $duration;
            }

            $this->time = $nextEvent;

            if ($nextEvent == $arrivalTime) {
                $this->processQueueEntry(0);
                $arrivalTime = $this->time + $this->getTime($this->arrivalRange);
            } else {
                foreach ($this->queues as $idx => &$q) {
                    if (in_array($nextEvent, $q['serviceTimes'])) {
                        array_shift($q['queue']);
                        $q['serversBusy']--;
                        array_shift($q['serviceTimes']);
                        if (isset($this->queues[$idx + 1])) {
                            $this->processQueueEntry($idx + 1);
                        }
                        break;
                    }
                }
            }
        }
    }

    private function processQueueEntry($queueIdx) {
        $q = &$this->queues[$queueIdx];
        if (count($q['queue']) < $q['capacity']) {
            $q['queue'][] = $this->time;
            if ($q['serversBusy'] < $q['servers']) {
                $q['serviceTimes'][] = $this->time + $this->getTime($q['serviceRange']);
                $q['serversBusy']++;
            }
        } else {
            $this->losses++;
        }
    }

    public function report() {
        $report = ['filas' => [], 'clientes_perdidos' => $this->losses, 'tempo_total' => $this->time];
        foreach ($this->queues as $i => $q) {
            $report['filas']["Fila_" . ($i + 1)] = $q['stateDurations'];
        }
        return $report;
    }
}

$options = getopt("", ["queues:", "arrival_min:", "arrival_max:", "random:"]);

if (!isset($options['queues'], $options['arrival_min'], $options['arrival_max'])) {
    exit("Usage: php simulator.php --queues='[{\"servers\":2,\"capacity\":3,\"serviceRange\":[1.0,1.5]}]' --arrival_min=NUM --arrival_max=NUM --random=NUM1,NUM2,...\n");
}

$queues = json_decode($options['queues'], true);
$arrivalRange = [(float)$options['arrival_min'], (float)$options['arrival_max']];
$randomNumbers = isset($options['random']) ? array_map('floatval', explode(',', $options['random'])) : [];

if (empty($randomNumbers)) {
    for ($i = 0; $i < 100000; $i++) {
        $randomNumbers[] = mt_rand() / mt_getrandmax();
    }
}

$simulator = new DynamicTandemQueueSimulator($queues, $arrivalRange, $randomNumbers);
$simulator->simulate();
print_r($simulator->report());