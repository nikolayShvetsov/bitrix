<?

\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleBasketSaved',
    'updateUserGroups'
);


function updateUserGroups(Bitrix\Main\Event $event)
{
    foreach($event->getResults() as $previousResult)
        if($previousResult->getType()!=\Bitrix\Main\EventResult::SUCCESS)
            return;
    $order = $event->getParameter("ENTITY");
    $summCurent = $order->getPrice(); // сумма текущего заказа
    global $USER;
    $userId = $USER->GetID();
    $arFilter = Array("USER_ID" => $userId);
    $sql = CSaleOrder::GetList(array("DATE_INSERT" => "ASC"), $arFilter);
    $itog = 0;
    while ($result = $sql->Fetch())
    {
        $itog += $result['PRICE'];
    }

    $summ = intVal(ceil($itog));
    $defaultGroups = [1, 3, 4, 5, 6];
    $discountGroups = [9, 10, 11, 12];
    $arrGroupsUser = \Bitrix\Main\UserGroupTable::getList(
        array(
            'filter' => array('USER_ID' => $userId, 'GROUP.ACTIVE' => 'Y'),
            'select' => array('GROUP_ID', 'GROUP_CODE' => 'GROUP.STRING_ID', 'GROUP_NAME' => 'GROUP.NAME'),
            'order' => array('GROUP.C_SORT' => 'ASC'),
        )
    );?>

    <?
    // сейчас срабатывает при каждом обновлении страницы если в корзине есть товар. То есть сумму не учитывает почему-то

    ?>
    <? while ($arGroup = $arrGroupsUser->fetch()) { // цикл со всеми группами пользователя

        if (!in_array($arGroup["GROUP_ID"], $defaultGroups) && in_array($arGroup["GROUP_ID"], $discountGroups)) {

            $i = 1;
            while ($arrRulesCart = CSaleDiscount::GetByID($i)) { // цикл по всем правилам корзины
                $groupId = $arrRulesCart["XML_ID"];
                $arGroups = CUser::GetUserGroup($userId);    // все группы текущего пользователя

                if ($groupId == (int)$arGroup["GROUP_ID"] + 1) { // надо сравнивать со следующей группой чтоб переводить в группу выше, если соблюдены ее правила

                    $arrCond = explode("arOrder['ORDER_PRICE']", $arrRulesCart["UNPACK"]);
                    $firstRule = trim(explode(';', $arrCond[2])[0]);
                    $secondRule = trim(explode(';', $arrCond[4])[0]);
                    preg_match('/^\D*(?=\d)/', $firstRule, $firstRuleZnak);
                    $firstZnak = trim($firstRuleZnak[0]);
                    $firstRuleInt = preg_replace("/[^0-9]/", '', $firstRule);
                    $firstSumm = (int)$firstRuleInt;
                    preg_match('/^\D*(?=\d)/', $secondRule, $secondRuleZnak);
                    $secondRuleInt = preg_replace("/[^0-9]/", '', $secondRule);
                    $secondZnak = trim($secondRuleZnak[0]);
                    $secondSumm = (int)$secondRuleInt;

                    switch ($firstZnak) {
                        case ">=":
                            $strRule1 = $summ >= $firstSumm;
                            break;
                        case "<=":
                            $strRule1 = $summ <= $firstSumm;
                            break;
                        case "<":
                            $strRule1 = $summ < $firstSumm;
                            break;
                        case ">":
                            $strRule1 = $summ > $firstSumm;
                            break;
                    }

                    switch ($secondZnak) {
                        case ">=":
                            $strRule2 = $summ >= $secondSumm;
                            break;
                        case "<=":
                            $strRule2 = $summ <= $secondSumm;
                            break;
                        case "<":
                            $strRule2 = $summ < $secondSumm;
                            break;
                        case ">":
                            $strRule2 = $summ > $secondSumm;
                            break;
                    }

                    if ($strRule1 && $strRule2) { // двойное условие у суммы всех купленных до этого товаров

                        if (in_array($groupId, $discountGroups)) { // если в массиве со всеми правилами корзины есть следующий ID-шник
                            unset($arGroups[$groupId], $arGroups); // удаление из массива id текущей группы
                            $arGroups[] = $groupId;                            // добавление в массив id следующей по порядку группы
                        }
                        CUser::SetUserGroup($userId, $arGroups); // запись нового массива групп пользователя
                    }
                }


                $i++;
            }
            var_dump($summ);

//            if($summ > 50000 && $summ <= 100000) {
//                $arGroups = CUser::GetUserGroup($userId);
//                unset($arGroups[array_search(9, $arGroups)]);
//                $arGroups[] = 10;
//                CUser::SetUserGroup($userId, $arGroups);
//            }
//
//            if($summ > 100000 && $summ <= 200000) {
//                $arGroups = CUser::GetUserGroup($userId);
//                unset($arGroups[array_search(10, $arGroups)]);
//                $arGroups[] = 11;
//                CUser::SetUserGroup($userId, $arGroups);
//            }
//
//            if($summ > 200000) {
//                $arGroups = CUser::GetUserGroup($userId);
//                unset($arGroups[array_search(11, $arGroups)]);
//                $arGroups[] = 12;
//                CUser::SetUserGroup($userId, $arGroups);
//            }
        }
    }
}



?>
