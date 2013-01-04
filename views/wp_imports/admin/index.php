<?php
/******************************************************
**
** Wp Import
** index.php
**
** @description:管理画面ビュー
** @author: G.Maniwa
** @date: 2012-12-24
**
*****************************************************/
?>

<p>
WordPressのエクスポートファイルをインポートします。
</p>

<?php
echo $bcForm->create('WpImport', array('enctype'=>'multipart/form-data','url' => array('controller' => 'wp_imports', 'action' => 'index')));
?>

	<table cellpadding="0" cellspacing="0" id="FormTable" class="form-table">
		<tr>
			<th class="col-head"><label for="BlogPostName">WordPressエクスポートファイル</label>&nbsp;<span class="required">*</span></th>
			<td class="col-input">
				<?php echo $bcForm->input('xml', array('type' => 'file','accept'=>'application/xml')); ?>
			</td>
		</tr>
	</table>


<div class="submit">

	<?php echo $bcForm->submit('インポート', array('div' => false, 'class' => 'btn-orange button')) ?>

</div>

<?php

echo $bcForm->end();

?>