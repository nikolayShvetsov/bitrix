<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Generator Big XML");

$path = '/home/bitrix/ext_www/mbbo.ru/import/import.xml';
$emptyPath = '/home/bitrix/www/bitrix/catalog_export/export-empty.xml';
$contentString = file_get_contents($path);
$finishString = '</offers>';
$offerString = '';

$reader = new XMLReader();
$reader->open($path);

while ($reader->read()) {

    if($reader->nodeType == XMLReader::ELEMENT) {
        // если находим элемент <Товар>
        if($reader->localName == 'Товар') {

            $value = $reader->expand(new DOMDocument());
            $sx = simplexml_import_dom($value);

            $arrName = (array)$sx->Наименование;
            $stringName = $arrName[0];

            $arrXmlId = (array)$sx->Ид;
            $xmlId = $arrXmlId[0];

            $arrRekv = (array)$sx->ЗначенияРеквизитов;

            $price = 55.50;
            $picture = 'image.jpg';
            $previewText = "текст для списка элементов";
            $detailText = "текст для детального просмотра";

            foreach ($arrRekv as $rekv) {
                foreach ($rekv as $key => $r) {
                    if($r->Наименование == 'ВидНоменклатуры') {
                        $arrCategory = (array)$r->Значение;
                        $category = $arrCategory[0];
                    }
                }
            }

            $arFilter = array('NAME' => $category);
            $rsSect = CIBlockSection::GetList(array('left_margin' => 'asc'),$arFilter);
            while ($arSect = $rsSect->GetNext())
            {
                $categoryId = $arSect["ID"];
            }

            if(CModule::IncludeModule('iblock')) {
                $el = new CIBlockElement;
                $PROP = array();
                $params = Array(
                    "max_len" => "100", // обрезает символьный код до 100 символов
                    "change_case" => "L", // буквы преобразуются к нижнему регистру
                    "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
                    "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
                    "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
                    "use_google" => "false", // отключаем использование google
                );

                $arLoadProductArray = Array(
                    "MODIFIED_BY"    => 1,
                    "IBLOCK_SECTION_ID" => $categoryId,
                    "IBLOCK_ID"      => 44,
                    "PROPERTY_VALUES"=> $PROP,
                    "NAME"           => $stringName,
                    "ACTIVE"         => "N",            // не активен
                    "PREVIEW_TEXT"   => $previewText,
                    "DETAIL_TEXT"    => $detailText,
                    "DETAIL_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"]."/upload/import/".$picture),
                    "CODE" => CUtil::translit($stringName, "ru" , $params)
                );

                if($ELEMENT_ID = $el->Add($arLoadProductArray)) {

                    $productFileds = array(
                        "ID" => $ELEMENT_ID, //ID добавленного элемента инфоблока
                        "VAT_ID" => 1, //выставляем тип ндс (задается в админке)
                        "VAT_INCLUDED" => "Y", //НДС входит в стоимость
                        "TYPE " => \Bitrix\Catalog\ProductTable::TYPE_PRODUCT //Тип товара
                    );

                    if (CCatalogProduct::Add($productFileds)){
                        //Элемент инфоблока превращен в товар

                        $arFieldsPrice = Array(
                            "PRODUCT_ID" => $ELEMENT_ID,							//ID добавленного товара
                            "CATALOG_GROUP_ID" => 1,						//ID типа цены
                            "PRICE" => $price,						//значение цены
                            "CURRENCY" =>  "RUB", 	// валюта
                        );

                        $dbPrice = \Bitrix\Catalog\Model\Price::getList([
                            "filter" => array(
                                "PRODUCT_ID" => $ELEMENT_ID,
                                "CATALOG_GROUP_ID" => 1
                            )
                        ]);

                        if (!($arPrice = $dbPrice->fetch())) {
                            //Если цены нет, то добавляем
                            $result = \Bitrix\Catalog\Model\Price::add($arFieldsPrice);

                            if ($result->isSuccess()){
                                echo "Добавили цену у товара у элемента каталога";
                            } else {
                                echo "Ошибка добавления цены у товара у элемента каталога";
                            }
                        }
                    }
                } else {

                    $arLoadProductArray = Array(
                        "MODIFIED_BY"    => 1,
                        "IBLOCK_SECTION_ID" => $categoryId,
                        "IBLOCK_ID"      => 44,
                        "PROPERTY_VALUES"=> $PROP,
                        "NAME"           => $stringName,
                        "ACTIVE"         => "N",            // не активен
                        "PREVIEW_TEXT"   => $previewText,
                        "DETAIL_TEXT"    => $detailText,
                        "DETAIL_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"]."/upload/import/".$picture),
                    );

                    $params2 = Array(
                        "max_len" => "100", // обрезает символьный код до 100 символов
                        "change_case" => "L", // буквы преобразуются к нижнему регистру
                        "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
                        "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
                        "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
                        "use_google" => "false", // отключаем использование google
                    );

                    $code = CUtil::translit($stringName, "ru" , $params2);

                    $arSelect = Array("ID");
                    $arFilter = Array("IBLOCK_ID"=>44,"CODE"=>$code);
                    $res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
                    while($ob = $res->GetNextElement())
                    {
                        $arFields = $ob->GetFields();
                        $id = $arFields['ID'];
                    }

                    $update = $el->Update($id, $arLoadProductArray);

                    $arFieldsPrice = Array(
                        "PRODUCT_ID" => $id,
                        "CATALOG_GROUP_ID" => 1,
                        "PRICE" => $price,
                        "CURRENCY" =>  "RUB",
                    );

                    $dbPrice = \Bitrix\Catalog\Model\Price::getList([
                        "filter" => array(
                            "PRODUCT_ID" => $id,
                            "CATALOG_GROUP_ID" => 1
                        )
                    ]);

                    if ($arPrice = $dbPrice->fetch()) {
                        $result = \Bitrix\Catalog\Model\Price::update($arPrice["ID"], $arFieldsPrice);

                        if ($result->isSuccess()){
                            echo "Обновили цену у товара у элемента каталога";
                        } else {
                            echo "Ошибка обновления цены у товара у элемента каталога";
                        }
                    }



//                    echo "Error: " . $el->LAST_ERROR;
                }
            }

        }
    }
}

$reader->close();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
