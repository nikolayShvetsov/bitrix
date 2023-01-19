<?

namespace My\Parser;

ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
//require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

class Parser
{

    /**
     * создаем новый бренд
     */
    public function creatBrand($name) {

        $el = new \CIBlockElement;

        // параметры создания символьного кода элементы
        $params = Array(
            "max_len" => "100", // обрезает символьный код до 100 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        );

        // проставляем все обязательные для элемента поля
        $arLoadProductArray = Array(
            "MODIFIED_BY"    => 1, // элемент изменен админом
            "IBLOCK_ID"      => 39,
            "NAME"           => $name,
            "ACTIVE"         => "Y",
            "CODE" => \CUtil::translit($name, "ru" , $params)
        );

        // создаем элемент
        $PRODUCT_ID = $el->Add($arLoadProductArray);
        return $PRODUCT_ID;
    }


    /**
     * получаем ID нужного брэнда
     */
    public function getNeedleBrandId($connection, $brandName) {

        $arrBrands = self::getAllBrands($connection);
        $brand = mb_strtolower($brandName);
        $id = '';
        $inDB = false;

        foreach ($arrBrands as $key => $brandDb) {
            $newBrandDb = mb_strtolower($brandDb);
            if($brand == $newBrandDb){ // такой бренд есть в базе
                $id = $key;
                $inDB = true;
                break;
            }
        }

        if(!$inDB) { // если не нашлось в базе ничего
            $id = self::creatBrand($brand);
        }

        return $id;
    }


    /**
     * получаем все бренды из базы
     */
    public function getAllBrands($connection) {

        $arrResults = [];
        $query = 'SELECT
            b_iblock_element.ID,
            b_iblock_element.NAME
        FROM
            `b_iblock_element`
        WHERE b_iblock_element.IBLOCK_ID = 39';

        $result = $connection->query($query);

        while($arrResult = $result->fetch()) {
            $arrResults[$arrResult['ID']]['NAME'] = $arrResult['NAME'];
        }

        return $arrResults;
    }


    /**
     * заменить вывод описания на html
     */
    public static function updateHtmlBody($id) {

        $el = new \CIBlockElement;
        $arLoadProductArray = Array(
            "DETAIL_TEXT_TYPE" => "html",
            "PREVIEW_TEXT_TYPE" => "html",
        );
        $el->Update($id, $arLoadProductArray);
    }


    /**
     * Получение ID категории по ее названию
     */
    public static function getSectionId($name) {

        $sectionId = false;
        $arFilter = array('IBLOCK_ID' => 59, 'NAME' => $name);
        $arSelect = array('ID');
        $rsSect = \CIBlockSection::GetList(
            Array("SORT"=>"ASC"), //сортировка
            $arFilter, //фильтр (выше объявили)
            false, //выводить количество элементов - нет
            $arSelect //выборка вывода, нам нужно только название, описание, картинка
        );
        while ($arSect = $rsSect->GetNext()) {
            $sectionId = $arSect['ID'];
        }

        return $sectionId;
    }


    /**
     * Обновление цены у товара
     */
    public static function updatePrice($type, $price, $id) {

        $arFields = array(
            "PRODUCT_ID" => $id,
            "CATALOG_GROUP_ID" => $type,
            "PRICE" => $price,
            "CURRENCY" => "RUB"
        );

        $dbPrice = \Bitrix\Catalog\Model\Price::getList([
            "filter" => array(
                "PRODUCT_ID" => $id,
                "CATALOG_GROUP_ID" => $type
            )
        ]);

        if ($arPrice = $dbPrice->fetch()) {
            \Bitrix\Catalog\Model\Price::delete($arPrice['ID']);
            \Bitrix\Catalog\Model\Price::add($arFields, true);

        } else {
            \Bitrix\Catalog\Model\Price::add($arFields, true);
        }
    }


    /**
     * Обновление продукта, который уже есть в базе данных
     */
    public static function updateProduct($id, $article, $product) {

        $price = (float)$product['price'];
        $zakup = (float)$product['zakup'];
        $rrc = (int)str_replace(',', '.', trim($product['rrc']));
        $extraCharge = $zakup/$price;
        $extraCharge = number_format($extraCharge, 2, '.', '');

        \CIBlockElement::SetPropertyValuesEx($id,59,['10656' => $extraCharge]);


        $arFields = array(
            "VAT_ID" => 1,
            "VAT_INCLUDED" => "Y",
            "QUANTITY" => $product['quantity'],
            "WEIGHT" => $product['weight'],
            "MEASURE" => 5, // единица измерения штуки
        );
        \Bitrix\Catalog\Model\Product::Update($id, $arFields);

        // обновляем закупочную цену у товара
        $type = 2;
//        self::updatePrice($type, $zakup, $id);

        // обновляем розничную цену у товара
        $type = 1;
//        self::updatePrice($type, $rrc, $id);
    }


    /**
     * Получение всех продуктов из базы
     */
    public static function getProductsFromDB($connection, $likeName) {

        $query = 'SELECT
            b_iblock_element.NAME,
            b_iblock_element.ID,
            b_catalog_price.PRICE,
            b_catalog_product.QUANTITY,
            b_iblock_element_property.VALUE as SITE_PARS,
            (SELECT VALUE FROM b_iblock_element_property WHERE b_iblock_element_property.IBLOCK_ELEMENT_ID = b_iblock_element.ID AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10655) as CODE_JDE
        FROM
            `b_iblock_element`
        LEFT JOIN b_catalog_price ON b_catalog_price.PRODUCT_ID = b_iblock_element.ID
        LEFT JOIN b_catalog_product ON b_catalog_product.ID = b_iblock_element.ID
        LEFT JOIN b_iblock_element_property ON ((b_iblock_element.ID = b_iblock_element_property.IBLOCK_ELEMENT_ID) AND b_iblock_element_property.IBLOCK_PROPERTY_ID = 10632)
        WHERE b_iblock_element.IBLOCK_ID = 59 AND b_iblock_element_property.VALUE LIKE "%' . $likeName . '%"';

        $result = $connection->query($query);
        $arrResults = [];

        while($arrResult = $result->fetch()) {
            $arrResults[$arrResult['CODE_JDE']]['ID'] = $arrResult['ID'];
            $arrResults[$arrResult['CODE_JDE']]['PRICE'] = $arrResult['PRICE'];
            $arrResults[$arrResult['CODE_JDE']]['QUANTITY'] = $arrResult['QUANTITY'];
        }

        return $arrResults;
    }


    /**
     * Получение картинки для товара
     */
    public static function getPic($url, $id, $more, $folder) {

        $pic = '';

        if ($more == 1) {
            if (strpos($url, ',') !== false) {
                $arrUrls = explode(',', $url);
                $lastKey = array_key_last($arrUrls);
                $divider = ',';
                foreach ($arrUrls as $key => $newUrl) {
                    if ($key == $lastKey) { $divider = ''; }
                    $filename = '/home/bitrix/ext_www/omess.ru/upload/' . $folder . '/' . $id . '_' . $key . '.jpg';
                    $pic .= self::uploadPic($filename, $url, $id, $key, $folder) . $divider;

                }
            } else {
                $filename = '/home/bitrix/ext_www/omess.ru/upload/' .$folder . '/' . $id . '_0.jpg';
                $pic = self::uploadPic($filename, $url, $id, 0, $folder);
            }
        } else {

            $filename = '/home/bitrix/ext_www/omess.ru/upload/' . $folder . '/' . $id . '.jpg';

            $pic = self::uploadPic($filename, $url, $id, null, $folder);
        }

        return $pic;
    }


    /**
     * загрузка картинки
     */
    public static function uploadPic($filename, $url, $id, $key, $folder) {

        $pic = '';

        if(!is_null($key)) {
            $key = '_' . $key;
        }

        if (!file_exists($filename)) { // если файла еще нет на сервере
            $headers = get_headers($url);
            $answer = $headers[0];
            if (strpos($answer, '200') != false) {
                $img = $filename;
                file_put_contents($img, file_get_contents($url));
                $pic = '/upload/' . $folder . '/' . $id . $key . '.jpg';
            }
        } else {
            $pic = '/upload/' . $folder . '/' . $id . $key . '.jpg';
        }

        return $pic;
    }


    /**
     * Обнуление цены и количества у товара, который есть на сайте, но нет в xml-прайсе
     */
    public static function nullQuantity($arrResults, $connection) {

        foreach ($arrResults as $code => $product) { // переобход по всем товарам, которые есть на сайте, но нет в прайсе
            $id = $product['ID'];

            self::updatePrice(1, 0, $id);
            self::updatePrice(2, 0, $id);

            $query3 = 'UPDATE `b_catalog_product` SET `QUANTITY` = 0 WHERE `b_catalog_product`.`ID` = ' . $id; // ставим у таких товаров количество 0
            $result = $connection->query($query3);
        }
    }


    /**
     * деактивация товара на сайте
     */
    public static function deactivateProduct($id) {
        $el = new CIBlockElement;
        $arLoadProductArray = Array("ACTIVE" => "N");
        $el->Update($id, $arLoadProductArray);
    }


    /**
     * проверка нет ли на сайте уже товара с таким CODE_JDE
     */
    public static function checkCodeJDE($code) {

        $arFields = [];
        $arSelect = Array("ID");
        $arFilter = Array("IBLOCK_ID"=>59, "PROPERTY_CODE_JDE" => $code);
        $res = \CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
        while($ob = $res->GetNextElement())
        {
            $arFields[] = $ob->GetFields();
        }

        return empty($arFields);
    }


    /**
     * Парсинг csv-файла
     */
    public static function parseCsv($file, $idCode) {
        $arrMatrix = file($file);
        $arrHeaders = explode(';', $arrMatrix[0]);

        unset($arrMatrix[0]);
        $arrRes = [];
        foreach ($arrMatrix as $row) {
            $arrLine = explode(';', $row);
            $code = trim($arrLine[$idCode]);

            foreach ($arrHeaders as $key => $header) {
                $arrRes[$code][trim($header)] = trim($arrLine[$key]);
            }
        }
        return $arrRes;
    }


    /**
     * Получение списка всех категорий из xml-файла
     */
    public static function getCategoriesFromXml($path) {

        $reader = new \XMLReader();
        $reader->open($path);
        $i = 0;
        $categories = [];

        while ($reader->read()) {
            if ($reader->nodeType == \XMLReader::ELEMENT) {
                if ($reader->localName == 'category') {
                    $value = $reader->expand(new \DOMDocument());
                    $sx = simplexml_import_dom($value);
                    $data = (array)$sx;
                    $id = $data["@attributes"]['id'];
                    $parentId = $data["@attributes"]['parentId'];
                    $nameCategory = $data[0];
                    $categories[$id]['name'] = $nameCategory;
                    $categories[$id]['parent'] = $parentId;
                }
            }
        }

        return $categories;
    }



    /**
     * Переопределение порядка в массиве
     */
    public static function array_reorder($array, $oldIndex, $newIndex) {
        array_splice(
            $array,
            $newIndex,
            count($array),
            array_merge(
                array_splice($array, $oldIndex, 1),
                array_slice($array, $newIndex, count($array))
            )
        );
        return $array;
    }

}
