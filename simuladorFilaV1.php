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

    public function statePercentages() {
        $totalTime = array_sum($this->stateDurations);
        $percentages = [];
        foreach ($this->stateDurations as $state => $duration) {
            $percentages[$state] = round(($duration / $totalTime) * 100, 2);
        }
        ksort($percentages);
        return $percentages;
    }
}

$randomNumbers = [0.8, 0.7, 0.8, 0.6, 0.2, 0.5];

$simulatorCafe = new QueueSimulator(1, 5, [2.0, 5.0], [3.0, 5], $randomNumbers);
$simulatorCafe->simulate();
echo "Fila G/G/1/5 - Cafeteria A: \n";
print_r($simulatorCafe->statePercentages());


$randomNumbers = [0.8, 0.7, 0.8, 0.6, 0.2, 0.5];

$simulatorCafe = new QueueSimulator(2, 5, [2.0, 5.0], [3.0, 5], $randomNumbers);
$simulatorCafe->simulate();
echo "Fila G/G/2/5 - Cafeteria B: \n";
print_r($simulatorCafe->statePercentages());