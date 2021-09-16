<?php 
use MapasCulturais\i;

$app = MapasCulturais\App::i();

$slug = $plugin->getSlug();
$name = $plugin->getName();

$route = MapasCulturais\App::i()->createUrl($slug, 'export', ['opportunity' => $opportunity]);    
?>

<a class="btn btn-default download btn-export-cancel" ng-click="editbox.open('<?= $slug ?>-export', $event)" rel="noopener noreferrer">CSV <?= $name ?></a>
<!-- Formulário -->
<edit-box id="<?= $slug ?>-export" position="top" title="CSV <?= $name ?>" cancel-label=<?= i::__("Cancelar") ?> close-on-cancel="true">
    <form class="form-export-<?= $slug ?>" action="<?= $route ?>" method="POST">
        <label for="from"><?= i::__("Data inicial") ?></label>
        <input type="date" name="from" id="from">
        
        <label for="to"><?= i::__("Data final") ?></label>
        <input type="date" name="to" id="to"> <br>

        <label for="to"><?= i::__("Identificação do lote") ?></label>
        <input type="text" name="lot" id="to" placeholder="Identificação do lote de pagamento Ex.: Lote 01"> <br>

        <div>
            <input type="checkbox" name="ignorePreviousLot">
            <label for="to"><?= i::__("Ignorar inscrições de lotes anteriores") ?></label>
        </div>
        
        <br><label for="to"><?= i::__("Exportar somente") ?></label>
        <textarea name="filterRegistrations" id="filterRegistrations" cols="30" rows="5" placeholder="Insira aqui o número ou ID da inscrição, separados por virgula ou uma inscrição por linha"></textarea>
        # <?= i::__("Caso não queira filtrar entre datas, deixe os campos vazios.") ?>
        <button class="btn btn-primary download" type="submit"><?= i::__("Exportar") ?></button>
    </form>
</edit-box>
