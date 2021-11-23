<?
CModule::IncludeModule('sale');
$defaultGroups = [1, 3, 4, 5, 6];
$result = \Bitrix\Main\UserGroupTable::getList(
    array(
        'filter' => array('USER_ID' => $GLOBALS['USER']->GetID(), 'GROUP.ACTIVE' => 'Y'),
        'select' => array('GROUP_ID', 'GROUP_CODE' => 'GROUP.STRING_ID', 'GROUP_NAME' => 'GROUP.NAME'),
        'order' => array('GROUP.C_SORT' => 'ASC'),
    )
);?>
<h2>Вы состоите в следующих группах:</h2>
<ul>
<? while ($arGroup = $result->fetch()) {

	if (!in_array($arGroup["GROUP_ID"], $defaultGroups)) {
		echo "<li style='font-size:16px'>" . $arGroup["GROUP_NAME"] . "</li>";

		$i = 1;

		while ($arrRulesCart = CSaleDiscount::GetByID($i)) {
			$conditionsRule = $arrRulesCart["CONDITIONS"];
			$groupId = $arrRulesCart["XML_ID"];

			if ($groupId == $arGroup["GROUP_ID"]) { 
			$strSale = explode("'VALUE' => -", $arrRulesCart["APPLICATION"], 2)[1];
			$sale = (int)explode(", 'UNIT'", $strSale, 2)[0];
		?>
				<h2>Текущий уровень скидки - <?=$sale?>%</h2>
		<? }
			$i++;
		}
	}
} ?>
</ul>
?>
