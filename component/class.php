<? if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

class twoComponentClass extends CBitrixComponent {

    function showParam($arParams) {
        $arResult["CHISLO"] = $arParams["CHISLO"];
        $arResult["STEPEN"] = $arParams["STEPEN"];
        return $arResult;
    }

    public function executeComponent() {
        $this->arResult = array_merge($this->arResult, $this->showParam($this->arParams));
        $this->includeComponentTemplate();
    }

}
