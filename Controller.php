<?php

namespace GenericPaymentExport;

use DateTime;
use Normalizer;
use MapasCulturais\i;
use League\Csv\Writer;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use GenericPaymentExport\Plugin as GenericPaymentExport;

/**
 * Registration Controller
 *
 * By default this controller is registered with the id 'registration'.
 *
 *  @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 */
// class extends \MapasCulturais\Controllers\EntityController {
class Controller extends \MapasCulturais\Controllers\Registration
{
    protected $config = [];

    protected $instanceConfig = [];

    /**
     * Retorna uma instância do controller
     * @param string $controller_id 
     * @return GenericValidator 
     */
    static public function i(string $controller_id): \MapasCulturais\Controller
    {
        $instance = parent::i($controller_id);
        $instance->init($controller_id);

        return $instance;
    }

    protected function init($controller_id)
    {
        if (!$this->_initiated) {
            $this->plugin = GenericPaymentExport::getInstanceBySlug($controller_id);
            $this->config = $this->plugin->config;

            $this->_initiated = true;
        }
    }

    /**
     * @var Plugin
     */
    protected $plugin;

    public function setPlugin(Plugin $plugin)
    {
        $this->plugin = GenericPaymentExport::getInstanceBySlug($this->config['slug']);
        $this->config = $plugin->config;
        $this->config += $this->plugin->config;
    }

    protected function exportInit(Opportunity $opportunity)
    {
        $this->requireAuthentication();

        if (!$opportunity->canUser('@control')) {
            echo "Não autorizado";
            die();
        }

        //Seta o timeout
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');
    }

    public function filterRegistrations($values)
    {
        if (!$values) {
            return;
        }

        if (strpos(",", $values) > 0) {
            $registration = explode(",", $values);
        } else {
            $registration = explode("\n", $values);
        }

        $result = array_map(function ($index) {
            return  preg_replace('/[^0-9]/i', '', $this->normalizeString($index));
        }, $registration);

        if (count($registration) > 1) {
            return array_filter($result);
        }

        echo "Filtro por inscrições falhou";
        exit;
    }

    /**
     * Retrieve the registrations.
     * @param Opportunity $opportunity
     * @return Registration[]
     */
    protected function getRegistrations(Opportunity $opportunity, $returnIds = false)
    {
        $app = App::i();

        $plugin = $this->plugin;

        // registration status
        $status = intval($this->data["status"] ?? 1);
        $dql_params = [
            "opportunity_id" => $opportunity->id,
            "status" => $status,
        ];
        $from = $this->data["from"] ?? "";
        $to = $this->data["to"] ?? "";
        $filterRegistrations = $this->filterRegistrations($this->data["filterRegistrations"]) ?? null;

        if ($from && !DateTime::createFromFormat("Y-m-d", $from)) {
            throw new \Exception(i::__("O formato do parâmetro `from` é inválido."));
        }
        if ($to && !DateTime::createFromFormat("Y-m-d", $to)) {
            throw new \Exception(i::__("O formato do parâmetro `to` é inválido."));
        }
        $dql_from = "";


        if ($from) { // start date
            $dql_params["from"] = (new DateTime($from))->format("Y-m-d 00:00");
            $dql_from = "r.sentTimestamp >= :from AND";
        }
        $dql_to = "";
        if ($to) { // end date
            $dql_params["to"] = (new DateTime($to))->format("Y-m-d 00:00");
            $dql_to = "r.sentTimestamp <= :to AND";
        }

        if ($filterRegistrations) { // end date
            $dql_params["filterRegistration"] = $filterRegistrations;
            $dql_to = "r.id IN (:filterRegistration) AND";
        }

        $dql = "
            SELECT
                r.id
            FROM
                MapasCulturais\\Entities\\Registration r
            WHERE
                $dql_to
                $dql_from
                r.status = :status AND
                r.opportunity = :opportunity_id";

        $query = $app->em->createQuery($dql);
        $query->setParameters($dql_params);
        $result = $query->getResult();

        $registrations = [];

        foreach ($result as $registration) {

            $registrations[] = $registration['id'];
        }

        return $registrations;
    }

    /**
     * Expora planilha para pagamento
     */
    public function ALL_export()
    {
        $app = App::i();

        //Oportunidade que a query deve filtrar
        $opportunity_id = $this->data['opportunity'];
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        $this->exportInit($opportunity);

        $registrations_ids = $this->getRegistrations($opportunity, true);

        $filename = $this->generateCSV($registrations_ids, $opportunity);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . basename($filename));
        header('Pragma: no-cache');
        readfile($filename);
    }

    protected function generateCSV(array $registrations_ids, $opportunity)
    {
        $app = App::i();

        $prefix = $this->plugin->getSlug() . "_" . $this->config['file_name_prefix'];
        $headers = array_keys($this->config['fields']) ?? [];
        $fields =  $this->config['fields'];
        $treatment = $this->config['treatment'];
        $lot = $this->data["lot"] ?? null;
        $ignorePreviousLot = $this->data["ignorePreviousLot"] ?? null;
        
        if (!$lot) {
            echo i::__("Informe a identificação do lote.");
            exit;
        }

        if ($lot != "check") {

            if (!$ref = json_decode($opportunity->{$this->plugin->prefix('reference_export_exist')}, true)) {
                $ref = [trim($lot)];
            } else {

                if (!in_array($lot, $ref)) {
                    array_push($ref, trim($lot));
                } else {
                    echo i::__("Nome do lote {$lot} já utilizado em exportação enterior, tente outro nome.");
                    exit;
                }
            }

            $app->disableAccessControl();
            $opportunity->{$this->plugin->prefix('reference_export_exist')} = json_encode($ref);
            $opportunity->save(true);
            $app->enableAccessControl();
        }

        $csv_data = [];

        foreach ($registrations_ids as $i => $id) {           
            
            $registration = $app->repo("Registration")->find($id);
            
            if(!($evaluations = $app->repo("RegistrationEvaluation")->findBy(["registration" => $registration,"status" => 1]))){
               continue;
            }
            

            if (!$ref = json_decode($registration->{$this->plugin->prefix('reference_export')}, true)) {
                $ref = [trim($lot)];
            } else {

                if ($ignorePreviousLot) {
                    $app->log->debug("#" . ($i + 1) . " - Inscrição já exportada em lote anterior ---> " . $registration->id);
                    continue;
                }

                if ($lot != "check") {
                    if (!in_array($lot, $ref)) {
                        array_push($ref, trim($lot));
                    } else {
                        echo i::__("Nome do lote {$lot} já utilizado em exportação enterior, tente ooutro nome.");
                        exit;
                    }
                }
            }

            $this->registerRegistrationMetadata($opportunity);

            foreach ($fields as $key => $field) {
                if (is_callable($field)) {
                    $value = $field($registration, $key);
                } else if (is_string($field)) {
                    $value = $registration->$field;
                } else if (is_int($field)) {
                    $field = "field_{$field}";
                    $value = $registration->$field;
                } else if (is_array($field)) {
                    foreach ($field as $k =>  $value) {
                        if (is_int($value)) {
                            $field[$k]  = "field_{$value}";
                        } else {
                            $field[$k] = $value;
                        }
                    }
                } else if (empty($field) || $field == "") {
                    $value = "";
                } else {
                    $value = $field;
                }

                $csv_data[$i][$key] = $treatment($registration, $key, $field) ?? $value;
            }

            $app->log->debug("#" . ($i + 1) . " - Exportando inscrição ---> " . $registration->id);

            if ($lot != "check") {
                $app->disableAccessControl();
                $registration->{$this->plugin->prefix('reference_export')} = json_encode($ref);
                $registration->save(true);
                $app->enableAccessControl();

                $app->em->clear();
            }
        }



        $slug = $this->plugin->slug;
        $hash = md5(json_encode($csv_data));
        $dir = PRIVATE_FILES_PATH . $slug . '/';

        $filename =  $dir . "{$lot}---{$slug}-{$prefix}{$opportunity->id}-{$hash}.csv";

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }


        $stream = fopen($filename, "w");
        $csv = Writer::createFromStream($stream);
        $csv->setDelimiter(";");
        $csv->insertOne($headers);
        foreach ($csv_data as $csv_line) {
            $csv->insertOne($csv_line);
        }
        return $filename;
    }

    /**
     * Normaliza uma string
     *
     * @param string $valor
     * @return string
     */
    private function normalizeString($valor): string
    {
        $valor = Normalizer::normalize($valor, Normalizer::FORM_D);
        return preg_replace('/[^A-Za-z0-9 ]/i', '', $valor);
    }
}
