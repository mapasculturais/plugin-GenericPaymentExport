<?php

namespace GenericPaymentExport;

use Exception;
use Normalizer;
use MapasCulturais\i;
use MapasCulturais\App;

class Plugin extends \MapasCulturais\Plugin
{

    protected static $instance = null;

    function __construct(array $config = [])
    {

        $slug = $config['slug'] ?? null;

        if (!$slug) {
            throw new Exception(i::__('A chave de configuração "slug" é obrigatória no plugin para um validador de recurso'));
        }


        $config += [
            'plugin_enabled' => true,
            'file_name_prefix' => "op",
            'required_validations_for_export' => [],
            'treatment' => function ($registration, $key, $field) {

                $result = [
                    'TIPO_IDENTIFICACAO' => function () use ($registration, $field) {
                        $document = preg_replace('/[^0-9]/i', '', $registration->$field);
                        return (strlen($document) <= 11) ? 1 : 2;
                    },
                    'TIPO_CREDOR' => function () use ($registration, $field) {
                        $document = preg_replace('/[^0-9]/i', '', $registration->$field);
                        return (strlen($document) <= 11) ? 1 : 2;
                    },
                    'EMAIL' => function () use ($registration, $field) {
                        if (is_array($field)) {
                            foreach ($field as $value) {
                                if ($result = $registration->$value) {
                                    break;
                                }
                            }
                        } else {
                            $result = $registration->$field;
                        }

                        return mb_strtolower($result);
                    },
                    'CPF' => function () use ($registration, $field) {
                        $document = preg_replace('/[^0-9]/i', '', $registration->$field);
                        return (strlen($document) <= 11) ? $document : $document . "Este CPF é Inválido";
                    },
                    'NOME_SOCIAL' => function () use ($registration, $field) {
                        return trim($registration->$field);
                    },
                    'LOGRADOURO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Nome_Logradouro']);
                    },
                    'NUMERO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Num']);
                    },
                    'COMPLEMENTO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Complemento']);
                    },
                    'BAIRRO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Bairro']);
                    },
                    'MUNICIPIO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Municipio']);
                    },
                    'CEP' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_CEP']);
                    },
                    'ESTADO' => function () use ($registration, $field) {
                        $address = $registration->$field;
                        return trim($address['En_Estado']);
                    },
                    'TELEFONE' => function () use ($registration, $field) {
                        foreach ($field as $value) {
                            if ($result = $registration->$value) {
                                break;
                            }
                        }

                        return preg_replace('/[^0-9]/i', '', trim($result));
                    },                    
                    'NUM_BANCO' => function () use ($registration, $field) {
                        return $this->getNumberBank($registration->$field) ?? preg_replace('/[^0-9]/i', '', $registration->$field);
                    },
                    'AGENCIA_BANCO' => function () use ($registration, $field) {
                        $field_dv = "field_".$this->config['complement']['DIGITO_AGENCIA'];
                        $dv = (trim($registration->$field_dv) == "Não possui dígito" ? "" : trim($registration->$field_dv));
                                                
                        $branch = $registration->$field;

                        if (strlen($branch) >= 4) {
                            $result = $branch;
                        } else {
                            $result = str_pad($branch, 4, 0, STR_PAD_LEFT);
                        }

                        return $result.$dv;

                    },
                    'CONTA_BANCO' => function () use ($registration, $field) {

                        $field_dv = "field_".$this->config['complement']['DIGITO_CONTA'];
                        $dv = trim($registration->$field_dv);

                        $account = $registration->$field;
                        if (strlen($account) >= 9) {
                            $result = $account;
                        } else {
                            $result = str_pad($account, 9, 0, STR_PAD_LEFT);
                        }

                        return $result.$dv;
                    },
                    'INSCRICAO_ID' => function () use ($registration, $field) {
                        return trim($registration->id);
                    },
                    'ERROS_AGENCIA' => function () use ($registration, $field) {
                        $_error = "";
                        $field_branch = "field_".$this->config['fields']['AGENCIA_BANCO'];
                        $field_dv = "field_".$this->config['complement']['DIGITO_AGENCIA'];
                        
                        $_error.= (trim($registration->$field_dv) == "Não possui dígito" ? "--Falta digito verificador da agência \n" : "");

                        if (strlen($registration->$field_branch) >= 4) {
                            $_error.= "--Agência maior que 4 caracteres\n";
                        }

                        return $_error;
                    },
                    'ERROS_CONTA' => function () use ($registration, $field) {
                        $_error = "";
                        $field_dv = "field_".$this->config['fields']['CONTA_BANCO'];
                        $account = $registration->$field_dv;
                        if (strlen($account) >= 9) {
                            $_error = (strlen($account) == 9) ? "" : "--Conta maior que 9 caracteres\n";
                        }
                        return $_error;
                    }


                ];

                $callable = $result[$key] ?? null;

                return $callable ? $callable() : null;
            }
        ];


        parent::__construct($config);

        self::$instance[$config["slug"]] = $this;
    }

    function _init()
    {

        $app = App::i();

        $plugin = $this;

        // Insere botão para exportação da planilha
        $app->hook("template(opportunity.<<single|edit>>.sidebar-right):end", function () use ($plugin, $app) {
            /** @var \MapasCulturais\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            $is_opportunity_managed = $plugin->config["is_opportunity_managed_handler"]($opportunity);
            if ($is_opportunity_managed && $opportunity->canUser("@control")) {
                $this->part("GenericPaymentExport/validador-uploads", [
                    "entity" => $opportunity,
                    "plugin" => $plugin
                ]);
            }
            return;
        });
    }

    function register()
    {
        $app = App::i();

        $app->registerController($this->getSlug(), 'GenericPaymentExport\Controller');

        $this->registerMetadata('MapasCulturais\Entities\Registration', $this->prefix("reference_export"), [
            'label' => i::__('Refertência do lote exportado para pagamento'),
            'type' => 'json',
            'private' => true,
            'default' => ''
        ]);

        $this->registerMetadata('MapasCulturais\Entities\Opportunity', $this->prefix("reference_export_exist"), [
            'label' => i::__('Refertência dos lotes já exportados para pagamento'),
            'type' => 'json',
            'private' => true,
            'default' => ''
        ]);
    }

    public static function getInstanceBySlug(string $slug)
    {
        return  self::$instance[$slug];
    }

    public function getSlug()
    {
        return $this->config['slug'];
    }

    function getName(): string
    {
        return $this->_config["name"];
    }

    public function prefix($value)
    {
        return $this->getSlug()."_".$value;
    }

    /**
     * Normaliza uma string
     *
     * @param string $valor
     * @return string
     */
    private function normalizeString($valor): string
    {
        $valor = trim(preg_replace('/[^A-Za-z0-9 ]/i', '', $this->removeSpecialChar($valor)));
        return Normalizer::normalize($valor, Normalizer::FORM_D);
    }

    function removeSpecialChar($string){
        return preg_replace(array("/(á|à|ã|â|ä)/","/(Á|À|Ã|Â|Ä)/","/(é|è|ê|ë)/","/(É|È|Ê|Ë)/","/(í|ì|î|ï)/","/(Í|Ì|Î|Ï)/","/(ó|ò|õ|ô|ö)/","/(Ó|Ò|Õ|Ô|Ö)/","/(ú|ù|û|ü)/","/(Ú|Ù|Û|Ü)/","/(ñ)/","/(Ñ)/","/(ç)/","/(Ç)/"),explode(" ","a A e E i I o O u U n N c C"),$string);
    }

    public function getNumberBank(string $value)
    {
        $banks = [
            "341" => "Itaú Unibanco",
            "237" => "Bradesco",
            "001" => "Banco do Brasil",
            "104" => "Caixa Econômica Federal",
            "237" => "Bradesco",
            "341" => "Itaú Unibanco",
            "033" => "Santander",
            "260" => "Nubank",
            "077" => "Banco Inter",
            "212" => "Banco Original",
            "290" => "Pagseguro Internet",
            "323" => "Mercado Pago (Mercado Livre )",
            "336" => "C6 Bank",
            "246" => "Banco ABC Brasil S.A.",
            "075" => "Banco ABN AMRO S.A.",
            "121" => "Banco Agibank S.A.",
            "025" => "Banco Alfa S.A.",
            "065" => "Banco Andbank (Brasil) S.A.",
            "096" => "Banco B3 S.A.",
            "024" => "Banco BANDEPE S.A.",
            "318" => "Banco BMG S.A.",
            "752" => "Banco BNP Paribas Brasil S.A.",
            "107" => "Banco BOCOM BBM S.A.",
            "063" => "Banco Bradescard S.A.",
            "036" => "Banco Bradesco BBI S.A.",
            "394" => "Banco Bradesco Financiamentos S.A.",
            "237" => "Banco Bradesco S.A.",
            "218" => "Banco BS2 S.A.",
            "208" => "Banco BTG Pactual S.A.",
            "626" => "Banco C6 Consignado S.A.",
            "336" => "Banco C6 S.A.",
            "473" => "Banco Caixa Geral - Brasil S.A.",
            "040" => "Banco Cargill S.A.",
            "   " => "Banco Caterpillar S.A.",
            "739" => "Banco Cetelem S.A.",
            "233" => "Banco Cifra S.A.",
            "745" => "Banco Citibank S.A.",
            "   " => "Banco CNH Industrial Capital S.A.",
            "756" => "Banco Cooperativo do Brasil S.A. - BANCOOB",
            "748" => "Banco Cooperativo Sicredi S.A.",
            "222" => "Banco Credit Agricole Brasil S.A.",
            "505" => "Banco Credit Suisse (Brasil) S.A.",
            "   " => "Banco CSF S.A.",
            "003" => "Banco da Amazônia S.A.",
            "083" => "Banco da China Brasil S.A.",
            "707" => "Banco Daycoval S.A.",
            "   " => "Banco de Lage Landen Brasil S.A.",
            "654" => "Banco Digimais S.A.",
            "   " => "Banco Digio S.A.",
            "047" => "Banco do Estado de Sergipe S.A.",
            "037" => "Banco do Estado do Pará S.A.",
            "041" => "Banco do Estado do Rio Grande do Sul S.A.",
            "004" => "Banco do Nordeste do Brasil S.A.",
            "224" => "Banco Fibra S.A.",
            "   " => "Banco Fidis S.A.",
            "094" => "Banco Finaxis S.A.",
            "   " => "Banco Ford S.A.",
            "   " => "Banco GM S.A.",
            "612" => "Banco Guanabara S.A.",
            "   " => "Banco IBM S.A.",
            "012" => "Banco Inbursa S.A.",
            "604" => "Banco Industrial do Brasil S.A.",
            "077" => "Banco Inter S.A.",
            "249" => "Banco Investcred Unibanco S.A.",
            "184" => "Banco Itaú BBA S.A.",
            "029" => "Banco Itaú Consignado S.A.",
            "341" => "Banco Itaú Veículos S.A.",
            "479" => "Banco ItauBank S.A",
            "341" => "Banco Itaucard S.A.",
            "341" => "Banco Itauleasing S.A.",
            "376" => "Banco J. P. Morgan S.A.",
            "074" => "Banco J. Safra S.A.",
            "217" => "Banco John Deere S.A.",
            "600" => "Banco Luso Brasileiro S.A.",
            "243" => "Banco Master S.A.",
            "389" => "Banco Mercantil do Brasil S.A.",
            "370" => "Banco Mizuho do Brasil S.A.",
            "746" => "Banco Modal S.A.",
            "456" => "Banco MUFG Brasil S.A.",
            "169" => "Banco Olé Bonsucesso Consignado S.A.",
            "212" => "Banco Original S.A.",
            "623" => "Banco PAN S.A.",
            "611" => "Banco Paulista S.A.",
            "643" => "Banco Pine S.A.",
            "747" => "Banco Rabobank International Brasil S.A.",
            "   " => "Banco RCI Brasil S.A.",
            "633" => "Banco Rendimento S.A.",
            "120" => "Banco Rodobens S.A.",
            "422" => "Banco Safra S.A.",
            "033" => "Banco Santander  (Brasil)  S.A.",
            "743" => "Banco Semear S.A.",
            "276" => "Banco Senff S.A.",
            "630" => "Banco Smartbank S.A.",
            "366" => "Banco Socidade Generale Brasil S.A.",
            "299" => "Banco Sorocred S.A. - Banco Múltiplo (AFINZ)",
            "464" => "Banco Sumitomo Mitsui Brasileiro S.A.",
            "082" => "Banco Topázio S.A.",
            "   " => "Banco Toyota do Brasil S.A.",
            "634" => "Banco Triãngulo S.A.",
            "653" => "Banco Voiter S.A.",
            "   " => "Banco Volvo Brasil S.A.",
            "655" => "Banco Votorantim S.A.",
            "610" => "Banco VR S.A.",
            "119" => "Banco Western Union do Brasil S.A.",
            "102" => "Banco XP S.A.",
            "   " => "Banco Yamaha Motor do Brasil S.A.",
            "021" => "BANESTES S.A. Banco do Estado do Espírito Santo",
            "755" => "Bank of America Merrill Lynch Banco Múltiplo S.A.",
            "250" => "BCV - Banco de Crédito e Varejo S.A.",
            "144" => "BEXS Banco de Câmbio S.A.",
            "017" => "BNY Mellon Banco S.A.",
            "070" => "BRB - Banco de Brasília S.A.",
            "104" => "Caixa Econômica Federal",
            "320" => "China Construction Bank (Brasil) Banco Múltiplo S.A.",
            "477" => "Citibank N.A.",
            "487" => "Deutsche Bank S.A. - Banco Alemão",
            "062" => "Hipercard Banco Múltiplo S.A.",
            "269" => "HSBC Brasil S.A. - Banco de Investimento",
            "492" => "ING Bank N.V.",
            "341" => "Itaú Unibanco S.A.",
            "488" => "JPMorgan Chase Bank, National Association",
            "399" => "Kirton Bank S.A. - Banco Múltiplo",
            "128" => "MS Bank S.A. Banco de Câmbio",
            "254" => "Paraná Banco S.A.",
            "125" => "Plural S.A. - Banco Múltiplo",
            "   " => "Scania Banco S.A.",
            "751" => "Scotiabank Brasil S.A. Banco Múltiplo",
            "095" => "Travelex Banco de Câmbio S.A.",
            "129" => "UBS Brasil Banco de Investimento S.A.",
        ];

        $value = preg_replace('/[^A-Za-z]/i', "", $this->normalizeString(mb_strtolower($value)));
        foreach ($banks as $key => $bank) {
            $result = preg_replace('/[^A-Za-z]/i', "", $this->normalizeString(mb_strtolower($bank)));
            if ((string)$result === (string)$value) {
                return trim($key);
            }
        }
        return null;
    }
}
