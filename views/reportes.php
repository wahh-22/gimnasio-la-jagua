<?php
$r=($view ?? [])['report'] ?? throw new LogicException('Resumen de reportes no disponible.');
$bands=['past'=>'Vencidos (antes de hoy)','soon'=>'Vencen hoy a +7 días (inclusive)','later'=>'Posteriores a +7 días'];
?>
<section aria-labelledby="report-scope"><h2 id="report-scope">Resumen de la instalación</h2>
<p>Fecha de consulta: <time datetime="<?=escape($r['from'])?>"><?=escape($r['from'])?></time>. Ventana próxima: <?=escape($r['from'])?> a <?=escape($r['through'])?>, ambos días incluidos (reloj del servidor).</p>
<p>Clientes: todos los registrados, activos e inactivos. Periodos: todos los registros de mensualidad, incluidos históricos y solapados; <strong>no son clientes únicos ni indican por sí solos si un cliente está activo</strong>.</p>
<dl class="report-kpis">
<div><dt>Clientes registrados</dt><dd data-kpi="clients"><?=$r['clients']?></dd></div>
<div><dt>Periodos registrados</dt><dd data-kpi="periods"><?=$r['periods']?></dd></div>
<div><dt>Periodos que vencen hoy a +7 días</dt><dd data-kpi="soon"><?=$r['soon']?></dd></div>
</dl></section>
<?php if($r['periods']===0): ?><div class="empty"><h2>Aún no hay periodos registrados</h2><p>Los conteos de periodos son cero. Los gráficos mostrarán los registros reales de esta instalación, sin datos de ejemplo.</p><a href="?page=mensualidades">Consultar mensualidades</a></div><?php endif ?>
<div class="report-charts">
<section aria-labelledby="expiry-title"><h2 id="expiry-title">Periodos por vencimiento</h2><p>Cada barra representa una parte del total de <?=$r['periods']?> periodos.</p>
<div class="table" role="region" aria-label="Vencimientos: gráfico y conteos" tabindex="0"><table class="report-table"><caption>Vencimientos · conteos de periodos</caption><thead><tr><th scope="col">Franja</th><th scope="col">Periodos</th><th scope="col">Proporción del total</th></tr></thead><tbody>
<?php foreach($bands as $key=>$label): ?><tr><th scope="row"><?=escape($label)?></th><td data-band="<?=$key?>"><?=$r[$key]?></td><td><meter min="0" max="<?=max(1,$r['periods'])?>" value="<?=$r[$key]?>" aria-label="<?=escape($label)?>: <?=$r[$key]?> de <?=$r['periods']?> periodos"><?=$r[$key]?> de <?=$r['periods']?></meter></td></tr><?php endforeach ?>
</tbody></table></div></section>
<section aria-labelledby="plan-title"><h2 id="plan-title">Distribución por plan</h2><p>Periodos registrados por plan; se incluyen los planes sin periodos.</p>
<?php if(!$r['plans']): ?><p class="empty">No hay planes registrados.</p><?php else: ?>
<div class="table" role="region" aria-label="Planes: gráfico y conteos" tabindex="0"><table class="report-table"><caption>Planes · conteos de periodos</caption><thead><tr><th scope="col">Plan</th><th scope="col">Periodos</th><th scope="col">Proporción del total</th></tr></thead><tbody>
<?php foreach($r['plans'] as $plan): $n=(int)$plan['periods']; ?><tr><th scope="row"><?=escape($plan['name'])?></th><td data-plan="<?=(int)$plan['id']?>"><?=$n?></td><td><meter min="0" max="<?=max(1,$r['periods'])?>" value="<?=$n?>" aria-label="<?=escape($plan['name'])?>: <?=$n?> de <?=$r['periods']?> periodos"><?=$n?> de <?=$r['periods']?></meter></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section></div>
<p class="notice">Solo agregados administrativos. No se consultan medidas corporales, contactos ni valores monetarios. Para imprimir, use la función de impresión del navegador; los conteos de las tablas permanecen visibles.</p>
