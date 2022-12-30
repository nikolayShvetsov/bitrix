<?php

ini_set('display_errors',1);
ini_set("max_execution_time", 300000000);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

use My\Parser\Parser;
use My\Parser\Orion;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Application as App;

$connection = App::getConnection();

if (CModule::IncludeModule("my.parser")){

    $productsDB = Parser::getProductsFromDB($connection, 'optvideo');
    $urlCsv = 'http://optvideo.com/market/api/cart.php?ckey=638447a13f091&campaignid=113102&type=GetCatalogCsv';
    $csvPath = '/home/bitrix/ext_www/mbbo.ru/orion.csv';
    file_put_contents($csvPath, file_get_contents($urlCsv));
    $productsCsv = Orion::ParseCsv($csvPath, 2);
    $urlXML = 'http://optvideo.com/market/api/cart.php?ckey=638447a13f091&campaignid=113102&type=downloadyml';
    $xmlPath = '/home/bitrix/ext_www/mbbo.ru/orion.xml';
    file_put_contents($xmlPath, file_get_contents($urlXML));
    $xmlCategories = Parser::getCategoriesFromXml($xmlPath);
    $xmlProducts = Orion::getProductsFromXml($xmlPath, $xmlCategories);
    $actualProducts = Orion::getActualProducts($xmlProducts,$productsCsv);

//    echo 'В CSV: ' . count($productsCsv) . '<br>';
//    echo 'В XML: ' . count($xmlProducts) . '<br>';

    $i=0;
    foreach ($actualProducts as $key => $product) {

        $category = '';
        $title = '';
        $pic = '';
        $morePhotos = '';
        $images = [];

        $properties = Orion::getProductFromAPI($key);
        if($properties['Images']) {
            $arrImages = $properties['Images'];
            unset($properties['Images']);

            foreach ($arrImages as $item) {
                $images[] = $item["ImgPath"];
            }
            $pic = $images[0];
            if(count($images) > 1) {
                unset($images[0]);
                $morePhotos = implode(',', $images);
            }
        }

        if($properties[0]) {
            $arrCategoryTitle = $properties[0];
            unset($properties[0]);
            if($arrCategoryTitle[0]["ОБЩИЕ"]) {
                $category = $arrCategoryTitle[0]["ОБЩИЕ"];
            }
            if($arrCategoryTitle[1]["ОБЩИЕ"]) {
                $title = $arrCategoryTitle[1]["ОБЩИЕ"];
            }
        }

        $resultProperties = [];

        foreach ($properties as $keyC => $categoryProperty) {
            if($keyC == 'Дополнительные функции') {
                $keyC = 'Дополнительно';
            }

            if(($keyC == 'ОБЩИЕ') || ($keyC == 'Основные характеристики')) {
                $keyC = 'Main';
            }

            if($keyC == 'Интерфейсы') {
                $keyC = 'Разъемы и интерфейсы';
            }

            foreach ($categoryProperty as $arrProperty) {
                foreach ($arrProperty as $keyPr => $property) {
                    $resultProperties[$keyC][$keyPr] = $property;
                }
            }
        }

        ksort($resultProperties);

        if (array_key_exists($key, $productsDB)) {
            $productId = $productsDB[$key]['ID'];
            Orion::updateProduct($productId, $product, $resultProperties);
            unset($productsDB[$key]);
        } else {
            Orion::createProduct($product, $resultProperties);
        }



        if($i>50) {
            die();
        }
        $i++;

    }

    Orion::nullQuantity($productsDB, $connection);
}



