<?php
declare(strict_types=1);

class Aleatorio
{
    private int $a = 16807;
    private int $c = 11;
    private float $mod;
    private float $ultimoAleatorio;
    private int $semente;
    private int $tamanho;
    private int $qtAleatorios = 0;

    public function __construct(int $tamanho, int $semente)
    {
        $this->mod            = (2 ** 31) - 1;
        $this->tamanho        = $tamanho;
        $this->semente        = $semente;
        $this->ultimoAleatorio = $semente;
    }

    public function geraProximoAleatorio(): float
    {
        $this->ultimoAleatorio = fmod(($this->a * $this->ultimoAleatorio + $this->c), $this->mod);
        $this->qtAleatorios++;
        return $this->ultimoAleatorio / $this->mod;
    }

    public function getQtAleatorios(): int { return $this->qtAleatorios; }
    public function __toString(): string
    {
        return "Foram gerados {$this->qtAleatorios} números aleatórios (a={$this->a}, c={$this->c}, m={$this->mod})";
    }
}

class Evento
{
    public const CHEGADA  = 'CHEGADA';
    public const SAIDA    = 'SAIDA';
    public const PASSAGEM = 'PASSAGEM';

    private string $tipo;
    private float $tempo;
    private int $idOrigem;
    private ?int $idDestino;

    public function __construct(string $tipo, float $tempo, int $idOrigem, ?int $idDestino = null)
    {
        $this->tipo      = $tipo;
        $this->tempo     = $tempo;
        $this->idOrigem  = $idOrigem;
        $this->idDestino = $idDestino;
    }

    public function getTipo(): string  { return $this->tipo; }
    public function getTempo(): float  { return $this->tempo; }
    public function getIdOrigem(): int { return $this->idOrigem; }
    public function getIdDestino(): ?int { return $this->idDestino; }
}

class Fila
{
    public int $id;
    public int $capacidade;
    public float $chegadaInicial;
    public float $chegadaMinima;
    public float $chegadaMaxima;
    public float $saidaMinima;
    public float $saidaMaxima;
    public int $servidores;

    public int $populacaoAtual = 0;
    public int $perdidos       = 0;

    private array $filaDestino = [];
    private array $probabilidades = [];

    public function putToFilaDestino(int $dest, Fila $fila): void { $this->filaDestino[$dest] = $fila; }
    public function putToProbabilidades(int $dest, float $prob): void { $this->probabilidades[$dest] = $prob; }
    public function getFilaDestino(): array { return $this->filaDestino; }
    public function getProbabilidades(): array { return $this->probabilidades; }
}

class EscalonadorDeFilas
{
    private array $filas = [];
    public function __construct(array $filas = []) { $this->filas = $filas ?: [new Fila()]; }
    public function getFilas(): array { return $this->filas; }
    public function setFilas(array $filas): void { $this->filas = $filas; }
}

class PropertiesLoader
{
    public static function loadProperties(string $file): array
    {
        return function_exists('yaml_parse_file') ? yaml_parse_file($file) : Yaml::parseFile($file);
    }
}

class Simulador
{
    private const ARQUIVO_YML = 'application.yml';

    private int $qtdNumerosAleatorios;
    private EscalonadorDeFilas $escalonadorDeFilas;
    private array $eventosAcontecendo = [];
    private array $eventosAgendados   = [];
    private float $tempo = 0.0;
    private float $tempoAnterior = 0.0;
    private array $probabilidades = [];
    private int $semente;
    private Aleatorio $aleatorios;

    public function __construct()
    {
        $this->escalonadorDeFilas = new EscalonadorDeFilas();
        $this->mapearYamlParaPOJO();
        $this->aleatorios = new Aleatorio($this->qtdNumerosAleatorios, $this->semente);
    }

    public function simulacao(): void
    {
        foreach ($this->escalonadorDeFilas->getFilas() as $fila) {
            if ($fila->chegadaInicial > 0) {
                $this->agendaChegada($fila, $fila->chegadaInicial);
            }
        }

        while ($this->aleatorios->getQtAleatorios() < $this->qtdNumerosAleatorios && !empty($this->eventosAgendados)) {
            usort($this->eventosAgendados, fn($a, $b) => $a->getTempo() <=> $b->getTempo());
            $eventoAtual = array_shift($this->eventosAgendados);
            $this->eventosAcontecendo[] = $eventoAtual;

            $this->tempoAnterior = $this->tempo;
            $this->tempo        = $eventoAtual->getTempo();

            $filaAtual   = $this->escalonadorDeFilas->getFilas()[$eventoAtual->getIdOrigem()];
            $filaDestino = $eventoAtual->getIdDestino() !== null ? $this->escalonadorDeFilas->getFilas()[$eventoAtual->getIdDestino()] : null;

            switch ($eventoAtual->getTipo()) {
                case Evento::CHEGADA:  $this->chegada($filaAtual);                    break;
                case Evento::SAIDA:    $this->saida($filaAtual);                      break;
                case Evento::PASSAGEM: $this->passagem($filaAtual, $filaDestino);     break;
            }
        }
        $this->exibirProbabilidade();
    }

    private function chegada(Fila $filaAtual): void
    {
        $this->ajustarProbabilidade();

        if ($this->filaPodeAtender($filaAtual)) {
            $filaAtual->populacaoAtual++;
            if ($filaAtual->populacaoAtual <= $filaAtual->servidores) {
                $this->agendaSaida($filaAtual);
            }
        } else {
            $filaAtual->perdidos++;
        }
        $this->agendaChegada($filaAtual);
    }

    private function saida(Fila $filaAtual): void
    {
        $this->ajustarProbabilidade();
        $filaAtual->populacaoAtual--;

        if ($filaAtual->populacaoAtual >= $filaAtual->servidores) {
            $this->agendaSaida($filaAtual);
        }

        $destino = $this->sorteio($filaAtual);
        if ($destino !== null) {
            $this->agendaPassagem($filaAtual, $destino);
        }
    }

        private function passagem(Fila $origem, ?Fila $destino): void
    {
        $this->ajustarProbabilidade();

        if ($destino !== null) {
            if ($this->filaPodeAtender($destino)) {
                $destino->populacaoAtual++;
                if ($destino->populacaoAtual <= $destino->servidores) {
                    $this->agendaSaida($destino);
                }
            } else {
                $destino->perdidos++;
            }
        }
    }

    private function agendaChegada(Fila $fila, ?float $tempoInicial = null): void
    {
        $delta       = $this->gerarIntervalo($fila->chegadaMinima, $fila->chegadaMaxima);
        $tempoEvento = ($tempoInicial ?? $this->tempo) + $delta;
        $this->eventosAgendados[] = new Evento(Evento::CHEGADA, $tempoEvento, $fila->id);
    }

    private function agendaSaida(Fila $fila): void
    {
        $delta = $this->gerarIntervalo($fila->saidaMinima, $fila->saidaMaxima);
        $this->eventosAgendados[] = new Evento(Evento::SAIDA, $this->tempo + $delta, $fila->id);
    }

    private function agendaPassagem(Fila $origem, Fila $destino): void
    {
        $this->eventosAgendados[] = new Evento(Evento::PASSAGEM, $this->tempo, $origem->id, $destino->id);
    }

    private function filaPodeAtender(Fila $fila): bool
    {
        return $fila->capacidade === -1 || $fila->populacaoAtual < $fila->capacidade;
    }

    private function gerarIntervalo(float $min, float $max): float
    {
        if ($min < 0 || $max < 0) { return 0.0; }
        return $min + ($max - $min) * $this->aleatorios->geraProximoAleatorio();
    }

    private function sorteio(Fila $fila): ?Fila
    {
        $probs = $fila->getProbabilidades();
        if (empty($probs)) { return null; }
        $r = $this->aleatorios->geraProximoAleatorio();
        $acumulado = 0.0;
        foreach ($probs as $destId => $prob) {
            $acumulado += $prob;
            if ($r <= $acumulado) { return $fila->getFilaDestino()[$destId]; }
        }
        return null;
    }

    private function ajustarProbabilidade(): void
    {
        foreach ($this->escalonadorDeFilas->getFilas() as $fila) {
            if (!isset($this->probabilidades[$fila->id])) {
                $this->probabilidades[$fila->id] = [];
            }
            $idx = $fila->populacaoAtual;
            $this->probabilidades[$fila->id][$idx] ??= 0.0;
            $this->probabilidades[$fila->id][$idx] += ($this->tempo - $this->tempoAnterior);
        }
    }

    private function mapearYamlParaPOJO(): void
    {
        $dados                        = PropertiesLoader::loadProperties(self::ARQUIVO_YML);
        $this->qtdNumerosAleatorios   = (int) $dados['numeros-aleatorios'];
        $this->semente                = (int) $dados['semente'];

        $filas = [];
        foreach ($dados['filas'] as $cfg) {
            $f = new Fila();
            $f->id             = (int) ($cfg['id'] ?? 0);
            $f->capacidade     = (int) $cfg['capacidade'];
            $f->chegadaInicial = (float) ($cfg['chegada-inicial'] ?? -1);
            $f->chegadaMinima  = (float) ($cfg['chegada-minima'] ?? -1);
            $f->chegadaMaxima  = (float) ($cfg['chegada-maxima'] ?? -1);
            $f->saidaMinima    = (float) ($cfg['saida-minima'] ?? -1);
            $f->saidaMaxima    = (float) ($cfg['saida-maxima'] ?? -1);
            $f->servidores     = (int) $cfg['servidores'];
            $filas[$f->id]     = $f;
        }
        $this->escalonadorDeFilas->setFilas($filas);

        foreach ($dados['redes'] as $rede) {
            $origem  = (int) $rede['origem'];
            $dest    = (int) $rede['destino'];
            $prob    = (float) $rede['probabilidade'];
            $filas[$origem]->putToFilaDestino($dest, $filas[$dest]);
            $filas[$origem]->putToProbabilidades($dest, $prob);
        }
    }

    private function exibirProbabilidade(): void
    {
        echo "\n" . $this->aleatorios . "\n";
        foreach ($this->probabilidades as $id => $pFila) {
            echo "- Fila: {$id}\nProbabilidades:\n";
            $totalPct = 0.0; $tempoTotal = max(1e-9, $this->tempo);
            foreach ($pFila as $idx => $t) {
                $pct = ($t / $tempoTotal) * 100.0;
                printf("Posição %d : Value %.4f%%\n", $idx, $pct);
                $totalPct += $pct;
            }
            echo "${totalPct}%\n";
            echo "Perdidos " . $this->escalonadorDeFilas->getFilas()[$id]->perdidos . "\n";
            echo "Tempo total: {$this->tempo}\n\n";
        }
    }
}

if (php_sapi_name() === 'cli') {
    (new Simulador())->simulacao();
}
?>
