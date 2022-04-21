<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Generator Big XML");

$path = '/home/bitrix/www/bitrix/catalog_export/export_pkN.xml';
$emptyPath = '/home/bitrix/www/bitrix/catalog_export/export-empty.xml';
$contentString = file_get_contents($path);
$finishString = '</offers>';
//$contentString = str_replace('<', '&lt;', $contentString);
$offerString = '';

$reader = new XMLReader();
$reader->open($path);

$i = 1;
while ($reader->read()) {

    if($reader->nodeType == XMLReader::ELEMENT) {
        // если находим элемент <offer>
        if($reader->localName == 'offer') {

            $value = $reader->expand(new DOMDocument());
            $sx = simplexml_import_dom($value);

            $arrPrice = (array)$sx->price;
            $stringPrice = $arrPrice[0];

            $arrCategory = (array)$sx->categoryId;
            $stringCategory = $arrCategory[0];

            $arFilter = array('ID' => $stringCategory);
            $rsSect = CIBlockSection::GetList(array('left_margin' => 'asc'),$arFilter);
            while ($arSect = $rsSect->GetNext())
            {
                $categoryXmlId = $arSect["XML_ID"];
            }

            $data = array();
            $data['id'] = $reader->getAttribute('id');

            $arMeasure = \Bitrix\Catalog\ProductTable::getCurrentRatioWithMeasure($data['id']);
            $edinica = $arMeasure[$data['id']]['MEASURE']['SYMBOL_RUS'];

//            if ($data['id'] != 129995) {
//                continue;
//            }
//            var_dump(trim($arrDescr[1]));

            $offerString .= '<offer><id>' . $data['id'] . '</id>';

            if(CModule::IncludeModule('iblock') && $data['id']) {
                $arSelect = Array("NAME","XML_ID","PROPERTY_SITE_PARS","PROPERTY_CML2_ARTICLE");
                $arFilter = Array("IBLOCK_ID"=>44, "ID"=>$data['id']);
                $res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
                while($ob = $res->GetNextElement())
                {
                    $arFields = $ob->GetFields();
                    $name = str_replace('&quot;', '', $arFields["NAME"]);
                    $name = str_replace('&', '-', $name);
                    $site = $arFields["PROPERTY_SITE_PARS_VALUE"];
                    $article = $arFields["PROPERTY_CML2_ARTICLE_VALUE"];
                    $xmlProduct = $arFields["XML_ID"];
                }
            }

            $offerString .= '<xmlId>' . $xmlProduct . '</xmlId>';
            $offerString .= '<name>' . $name . '</name>';
            $offerString .= '<price>' . $stringPrice . '</price>';
            $offerString .= '<categoryXmlId>' . $categoryXmlId . '</categoryXmlId>';
            $offerString .= '<edinica>' . $edinica . '</edinica>';
            $offerString .= '<article>' . $article . '</article></offer>';
//            var_dump(str_replace('<', '&lt;', $offerString));
//            die();
        }



    }
    if ($i % 380000 == 0) {
        $contentStringEmpty = file_get_contents($emptyPath);
        $count = $i / 380000;
        $fileName = 'export-' . $count . '.xml';
        $fn = fopen($fileName, 'w');
        $contentStringEmpty .= $offerString;
        $fullString = $contentStringEmpty . $finishString;
        fwrite($fn, $fullString);
        $offerString = '';

        fclose($fn);
    }
    $i++;
}

$reader->close();

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
