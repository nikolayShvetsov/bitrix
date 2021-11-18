<?php

$_monthsList = array(
    "-01" => "Января",
    "-02" => "Февраля",
    "-03" => "Марта",
    "-04" => "Апреля",
    "-05" => "Мая",
    "-06" => "Июня",
    "-07" => "Июля",
    "-08" => "Августа",
    "-09" => "Сентября",
    "-10" => "Октября",
    "-11" => "Ноября",
    "-12" => "Декабря"
);

    $_mD = strtolower(FormatDate("-m", MakeTimeStamp($arResult['DATE'])));
    $creatDate = str_replace($_mD, " ".$_monthsList[$_mD]." ", strtolower(FormatDate("d-m Y", MakeTimeStamp($arResult['DATE']))));
    echo "Сегодня " . $creatDate . "<br>";

?>
