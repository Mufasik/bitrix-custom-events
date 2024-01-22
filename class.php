<?
use Bitrix\Highloadblock\HighloadBlockTable as HLBT;
CModule::IncludeModule('highloadblock');

class Bit1cExchange {
	/** список для проверки прошел обработку элемент или нет */
	static $ELEMENTS_1C_UPDATE = [];

	/** получение класса HL блока по ID */
	static function GetEntityDataClass($HlBlockId) {
		if (empty($HlBlockId) || $HlBlockId < 1) {
			return false;
		}
		$hlblock = HLBT::getById($HlBlockId)->fetch();	
		$entity = HLBT::compileEntity($hlblock);
		$entity_data_class = $entity->getDataClass();
		return $entity_data_class;
	}

	/** обработка HL блока с ценами для скрытия цен на сайте */
	function UpdatePricesHLB($price, $hide = true) {
		$entity_data_class = self::GetEntityDataClass(HIGHLOAD_HIDDEN_PRICES);
		$result = $entity_data_class::getList([
			'select' => ['*'],
			'filter' => [
				'UF_CATALOG_GROUP_ID' 	=> $price['CATALOG_GROUP_ID'],
				'UF_PRODUCT_ID'			=> $price['PRODUCT_ID'],
			],
		]);
		if ($hide) {
			if ($data = $result->Fetch()) {
				if ($price['PRICE'] != 0) {
					$entity_data_class::update($data['ID'], [
						'UF_CATALOG_GROUP_ID' 	=> $price['CATALOG_GROUP_ID'],
						'UF_PRICE' 				=> $price['PRICE'],
						'UF_PRODUCT_ID'			=> $price['PRODUCT_ID'],
						'UF_CURRENCY'			=> $price['CURRENCY'],
					]);
				}
			}
			else {
				$entity_data_class::add([
					'UF_CATALOG_GROUP_ID' 	=> $price['CATALOG_GROUP_ID'],
					'UF_PRICE' 				=> $price['PRICE'],
					'UF_PRODUCT_ID'			=> $price['PRODUCT_ID'],
					'UF_CURRENCY'			=> $price['CURRENCY'],
				]);
			}
		}
		else {
			if ($data = $result->Fetch()) {
				$dbPrice = $price['PRICE'] != 0 ? $price['PRICE'] : $data['UF_PRICE'];
				$entity_data_class::delete($data['ID']);
				return $dbPrice;
			}
			else {
				return $price['PRICE'];
			}
		}
	}

	/** переименовать все свойства убрать - (для сайта) */
    function IBlockPropRename(&$arFields) {
        $name = str_replace(IBLOCK_PROP_RENAME, "", $arFields["NAME"], $count);
        if ($count) {
            $arFields["NAME"] = $name;
        }
    }

	/** изменение элементов инфоблока */
	function OnBeforeIBlockElement(&$arFields) {
		if ($arFields['IBLOCK_ID'] == CATALOG_ID) {
			$id = $arFields['ID'];

			$props = $arFields['PROPERTY_VALUES'];

			if (isset($props[PROP_YOUTUBE]) and isset($props[PROP_YOUTUBE_ASPRO])) {
				$arFields['PROPERTY_VALUES'][PROP_YOUTUBE_ASPRO] = $props[PROP_YOUTUBE];
				$data = array_pop($props[PROP_YOUTUBE]);
			}

			// артикул для рекомендованных добавляем как id похожие товары
			if (isset($props[PROP_ASSOCIATED]) and isset($props[PROP_ASSOCIATED_ASPRO])) {
				$data = array_pop($props[PROP_ASSOCIATED]);
				$recommended = explode(',', str_replace(' ', '', $data['VALUE']));
				if ($recommended[0]) {
					$arFilter = ["IBLOCK_ID" => $arFields['IBLOCK_ID'], "PROPERTY_CML2_ARTICLE" => $recommended];
					$res = CIBlockElement::GetList([], $arFilter, false, false, ["ID"]);
					$id_recommended = [];
					while($ob = $res->GetNextElement()) {
						$fields = $ob->GetFields();
						$id_recommended[]['VALUE'] = $fields['ID'];
					}
					$arFields['PROPERTY_VALUES'][PROP_ASSOCIATED_ASPRO] = $id_recommended;
				} else {
					$arFields['PROPERTY_VALUES'][PROP_ASSOCIATED_ASPRO] = [];
				}
			}

			// объем и вес
			if (isset($props[PROP_SIZE]) and isset($props[PROP_WEIGHT]) and isset($props[PROP_TRAITS_ASPRO])) {
				$size = [];
				$weight = [];
				foreach ($props[PROP_TRAITS_ASPRO] as $item) {
					if ($item['VALUE'] != 0) {
						if ($item['DESCRIPTION'] == 'Объем') {
							$size[]['VALUE'] = $item['VALUE'];
						}
						if ($item['DESCRIPTION'] == 'Вес') {
							$weight[]['VALUE'] = $item['VALUE'];
						}
					}
				}
				$arFields['PROPERTY_VALUES'][PROP_SIZE] = $size;
				$arFields['PROPERTY_VALUES'][PROP_WEIGHT] = $weight;
			}
		}
		// деактивация торг предложений (не используются)
		if ($arFields['IBLOCK_ID'] == CATALOG_SKU_ID) {
			$arFields['ACTIVE'] = "N";
		}
	}

	/** добавление продукта */
	function OnAddProduct(&$arFields) {
		if (isset($arFields['ID'])) self::OnProduct($arFields['ID'], $arFields);
	}
	
	/** изменение продукта */
	function OnProduct($id, &$arFields) {
		// обновление торг каталога - размеры
		$res = CIBlockElement::GetPropertyValues(CATALOG_ID, ['ID' => $id], true, 
			['ID' => [PROP_HEIGHT, PROP_LENGTH, PROP_WIDTH] ]);
		if ($ar = $res->Fetch()) {
			$arFields['HEIGHT'] = $ar[PROP_HEIGHT];
			$arFields['LENGTH'] = $ar[PROP_LENGTH];
			$arFields['WIDTH'] = $ar[PROP_WIDTH];
		}
	}

	/** При выгрузке из 1с запускаем обновление цен т.к. непонятно скрывать или нет */
	function OnAfterIBlockElement(&$arFields) {
		if ($_REQUEST['mode']=='import' && $arFields['IBLOCK_ID'] == CATALOG_ID) {
			$db_res = CPrice::GetListEx([], ["PRODUCT_ID" => $arFields['ID']]);
			while ($ar_res = $db_res->Fetch()) {
				CPrice::Update($ar_res["ID"], [
					'PRODUCT_ID' => $ar_res['PRODUCT_ID'],
					'CATALOG_GROUP_ID' => $ar_res['CATALOG_GROUP_ID'],
					'PRICE' => $ar_res['PRICE'],
					'CURRENCY' => $ar_res['CURRENCY'],
				]);
			}
		}
	}

	/** цены из HL блоков */
	function OnBeforePriceAdd(&$arFields) {
		self::OnBeforePrice(0, $arFields);
	}

	/** цены из HL блоков */
	function OnBeforePrice($id, &$arFields) {
		$db_props = CIBlockElement::GetProperty(CATALOG_ID, $arFields['PRODUCT_ID'], [], ["ID"=>PROP_HIDE_PRICE]);
		if($ar_props = $db_props->Fetch()) {
			if ($ar_props['VALUE_ENUM'] == "Да") {
				self::UpdatePricesHLB($arFields);
				$arFields['PRICE'] = 0;
			}
			else if ($arFields['PRICE'] == 0) {
				$dbPrice = self::UpdatePricesHLB($arFields, false);
				$arFields['PRICE'] = $dbPrice;
			}
		}
	}

	/** меняем ФИО и добавляем ФИО ответственного из HL блока в поле Контактное лицо для типа плательщика юр лицо */
	function OnBeforeUserUpdate(&$arFields) {
		CModule::IncludeModule("sale");
		if (isset($arFields['EXTERNAL_AUTH_ID']) && $arFields['EXTERNAL_AUTH_ID'] == 'sale') {
			$db_sales = CSaleOrderUserProps::GetList([], ["USER_ID" => $arFields['ID'], "PERSON_TYPE_ID" => 2]);
			if ($ar_sales = $db_sales->Fetch()) {
				$companyName = $ar_sales['NAME'];
				$companyShortName = "";
				$contactName = "";
				if ($companyName) {
					$entity_data_class = self::GetEntityDataClass(HIGHLOAD_COMPANY);
					$result = $entity_data_class::getList([
						'select' => ['*'],
						'filter' => [
							'UF_NAME' => $companyName,
						],
					]);
					if ($arItem = $result->Fetch()) {
						$companyShortName = $arItem['UF_PARTNER'];
					}
				}
				if ($companyShortName) {
					$entity_data_class = self::GetEntityDataClass(HIGHLOAD_CONTACT);
					$result = $entity_data_class::getList([
						'select' => ['*'],
						'filter' => [
							'UF_VLADELETS' => $companyShortName,
							'UF_CRMDOLZHNOST' => 'Для сайта',
						],
					]);
					if ($arItem = $result->Fetch()) {
						$contactName = $arItem['UF_NAME'];
						$arFields['TITLE'] = $companyShortName;
						$arFields['NAME'] = $arItem['UF_CRMIMYA'];
						$arFields['LAST_NAME'] = $arItem['UF_CRMFAMILIYA'];
						$arFields['SECOND_NAME'] = $arItem['UF_CRMOTCHESTVO'];
					}
				}
				if ($contactName) {
					$db_props = CSaleOrderUserPropsValue::GetList([], ["USER_PROPS_ID" => $ar_sales['ID'], "ORDER_PROPS_ID" => 12]);
					if ($ar_props = $db_props->Fetch()) {
						CSaleOrderUserPropsValue::Update($ar_props['ID'], [
							'VALUE' => $contactName,
						]);
					}
					else {
						CSaleOrderUserPropsValue::Add([
							'USER_PROPS_ID' => $ar_sales['ID'],
							'ORDER_PROPS_ID' => 12,
							"NAME" => "Контактное лицо",
							'VALUE' => $contactName,
						]);
					}
					$db_props = CSaleOrderUserPropsValue::GetList([], ["USER_PROPS_ID" => $ar_sales['ID'], "ORDER_PROPS_ID" => 8]);
					if ($ar_props = $db_props->Fetch()) {
						CSaleOrderUserPropsValue::Update($ar_props['ID'], [
							'VALUE' => $companyShortName,
						]);
					}
				}
			}
			else {
				// создаем тип юр лицо если отсутствует (отдельная обработка для контактов без инн)
				$fio = [];
				if (isset($arFields['LAST_NAME']) && $arFields['LAST_NAME']) $fio[] = $arFields['LAST_NAME'];
				if (isset($arFields['NAME']) && $arFields['NAME']) $fio[] = $arFields['NAME'];
				if (isset($arFields['SECOND_NAME']) && $arFields['SECOND_NAME']) $fio[] = $arFields['SECOND_NAME'];
				$contactName = implode(' ', $fio);
				$companyShortName = "";
				$entity_data_class = self::GetEntityDataClass(HIGHLOAD_CONTACT);
				$result = $entity_data_class::getList([
					'select' => ['*'],
					'filter' => [
						'UF_NAME' => $contactName,
						'UF_CRMDOLZHNOST' => 'Для сайта',
					],
				]);
				if ($arItem = $result->Fetch()) {
					$companyShortName = $arItem['UF_VLADELETS'];
					$arFields['TITLE'] = $companyShortName;
				}
				if ($companyShortName) {
					$id = CSaleOrderUserProps::Add([
						"NAME" => $companyShortName,
						"USER_ID" => $arFields['ID'],
						"PERSON_TYPE_ID" => 2
					]);
					if ($id) {
						CSaleOrderUserPropsValue::Add([
							'USER_PROPS_ID' => $id,
							'ORDER_PROPS_ID' => 12,
							"NAME" => "Контактное лицо",
							'VALUE' => $contactName,
						]);
						CSaleOrderUserPropsValue::Add([
							'USER_PROPS_ID' => $id,
							'ORDER_PROPS_ID' => 8,
							"NAME" => "Название компании",
							'VALUE' => $companyShortName,
						]);
					}
				}
			}
		}
	}

	/** проверка у пользователя тип плательщика на юр лицо для order ajax*/
	function isUserType2($id) {
		CModule::IncludeModule("sale");
		$db_sales = CSaleOrderUserProps::GetList([], ["USER_ID" => $id, 'PERSON_TYPE_ID' => 2]);
		if ($db_sales->Fetch()) {
			return true;
		}
		return false;
	}

	/** получение ID родителя у торг пред и с названием (базовый) */
	function getParentID($id) {
		if (CIBlockElement::GetIBlockByID($id) != CATALOG_SKU_ID) return false;
		$res = CIBlockElement::GetByID($id)->GetNext();
		if (mb_strpos($res['NAME'], IBLOCK_SKU_NAME)) {
			$parent = CCatalogSKU::GetProductInfo($id);
			return $parent['ID'];
		}
		return false;
	}

	/** продукт - редактирование цен */
	function OnAfterPrice(\Bitrix\Main\Event $event) {
		$fields = $event->getParameter('fields');
		if ($id = self::getParentID($fields['PRODUCT_ID'])) {
			$db_res = CPrice::GetListEx([], ["PRODUCT_ID" => $id, "CATALOG_GROUP_ID" => $fields['CATALOG_GROUP_ID']]);
			$arFields = [
				"PRODUCT_ID" => $id,
				"CATALOG_GROUP_ID" => $fields['CATALOG_GROUP_ID'],
				"PRICE" => $fields['PRICE'],
				"CURRENCY" => $fields['CURRENCY'],
			];
			if ($ar_res = $db_res->Fetch()) {
				CPrice::Update($ar_res["ID"], $arFields);
			}
			else {
				CPrice::Add($arFields);
			}
		}
	}

	/** продукт - редактирование остатка */
	function OnAfterProduct(\Bitrix\Main\Event $event) {
		$fields = $event->getParameter('fields');
		$product = $event->getParameter('id');
		if ($id = self::getParentID($product['ID'])) {
			CCatalogProduct::Update($id, ["QUANTITY" => $fields['QUANTITY']]);
		}
	}

	/** продукт - редактирование складов */
	function OnAfterStoreProduct($id, $arFields) {
		if ($id = self::getParentID($arFields['PRODUCT_ID'])) {
			CCatalogStoreProduct::UpdateFromForm([
				"PRODUCT_ID" => $id,
				"STORE_ID" => $arFields['STORE_ID'],
				"AMOUNT" => $arFields['AMOUNT'],
			]);
		}
	}

	/** замена детальной картинки при выгрузке из 1с */
	function OnAfterIBlockElementPictureRename(&$arFields) {
		$ELEMENT_ID = $arFields['ID'];
		$IBLOCK_ID = $arFields['IBLOCK_ID'];
		// проверки для отключения зацикливания
		if (!isset(Bit1cExchange::$ELEMENTS_1C_UPDATE[$ELEMENT_ID])) Bit1cExchange::$ELEMENTS_1C_UPDATE[$ELEMENT_ID] = true;
		if ($_REQUEST['mode']=='import' && $IBLOCK_ID == CATALOG_ID && Bit1cExchange::$ELEMENTS_1C_UPDATE[$ELEMENT_ID]) {
			Bit1cExchange::$ELEMENTS_1C_UPDATE[$ELEMENT_ID] = false;
			$id = 0;
			$res = CIBlockElement::GetList([], ['ID' => $ELEMENT_ID], false, false, ["DETAIL_PICTURE"]);
			if ($ob = $res->GetNext()) {
				if ($ob['DETAIL_PICTURE']) $id = $ob['DETAIL_PICTURE'];
			}
			if ($id) {
				$arFile = CFile::GetFileArray($id);
				$src = $arFile['SRC'];
				// имя картинки если нет описания - имя элемента
				$name = $arFile['DESCRIPTION'] ? $arFile['DESCRIPTION'] : $arFields['NAME'];;
				// удаление спецсимволов для русских названий картинок
				$name = preg_replace('/[^A-Za-zА-Яа-я0-9 ,.()_-]/u', '', $name);
				// $name = Cutil::translit($name, "ru", ["replace_other"=>"-"]);
				$sub_dir = PICS_RENAME_PATH . $ELEMENT_ID;
				$file_name = "$name." . GetFileExtension($src);
				$savePath = $sub_dir . '/' . $file_name;
				// проверка на существование файла в бд
				$res = CFile::GetList([], ['SUBDIR' => $sub_dir, 'FILE_NAME' => $file_name]);
				if ($arr = $res->GetNext()) {
					$newFile = $arr['ID'];
				}
				else {
					$newFile = CFile::CopyFile($id, true, $savePath);
				}
				// обновление детальной картинки элемента с учетом нового имени и удалением старой
				$el = new CIBlockElement;
				$file = CFile::MakeFileArray($newFile);
				$file['name'] = $file_name;
				$res = $el->Update($ELEMENT_ID, ["DETAIL_PICTURE" => $file]);
				if ($res) {
					CFile::Delete($newFile);
				}
			}
		}
	}

	/** замена доп картинок при выгрузке из 1с с сортировкой по названию */
	function OnAfterIBlockElementSetPropertyPictureRename($ELEMENT_ID, $IBLOCK_ID, $PROPERTY_VALUES, $PROPERTY_CODE) {
		if ($_REQUEST['mode']=='import' && $IBLOCK_ID == CATALOG_ID) {
			$pictures = [];
			$res = CIBlockElement::GetProperty($IBLOCK_ID, $ELEMENT_ID, [], ["ID" => PROP_MORE_PHOTO]);
			while ($ob = $res->GetNext()) {
				if ($ob['VALUE']) $pictures[] = $ob['VALUE'];
			}
			// список для проверки на кол-во дубликатов и переименования на _2 _3 и т.д. и список для сортировки всех названий доп картинок и данные для вставки картинок
			$picturesName = [];
			$picturesSort = [];
			$picturesFile = [];
			foreach ($pictures as $id) {
				$arFile = CFile::GetFileArray($id);
				$src = $arFile['SRC'];
				// file_put_contents(LOG_FILENAME_INIT, date("Y-m-d H:i:s") . " $id in $src\n", FILE_APPEND);
				// имя картинки если нет описания - имя элемента или id картинки
				$desc = $id;
				$res = CIBlockElement::GetByID($ELEMENT_ID);
				if($ar_res = $res->GetNext()) $desc = $ar_res['NAME'];
				$name = $arFile['DESCRIPTION'] ? $arFile['DESCRIPTION'] : $desc;
				// удаление спецсимволов для русских названий картинок
				$name = preg_replace('/[^A-Za-zА-Яа-я0-9 ,.()_-]/u', '', $name);
				// $name = Cutil::translit($name, "ru", ["replace_other"=>"-"]);
				// сохранение с проверкой на дубли
				$sub_dir = PICS_RENAME_PATH . $ELEMENT_ID;
				$file_name = "$name." . GetFileExtension($src);
				$savePath = $sub_dir . '/' . $file_name;
				$fullPath = $_SERVER["DOCUMENT_ROOT"] . 'upload/' . $savePath;
				$picturesName[] = $file_name;
				if (file_exists($fullPath)) {
					$pathCount = array_count_values($picturesName);
					$pathNum = $pathCount[$file_name];
					if ($pathNum > 1) {
						$file_name = $name . "_$pathNum." . GetFileExtension($src);
						$savePath = $sub_dir . '/' . $file_name;
					}
				}
				// проверка на существование файла в бд
				$res = CFile::GetList([], ['SUBDIR' => $sub_dir, 'FILE_NAME' => $file_name]);
				if ($arr = $res->GetNext()) {
					$newFile = $arr['ID'];
				}
				else {
					$newFile = CFile::CopyFile($id, true, $savePath);
				}
				// добавляем для сортировки и добавляем для привязки с учетом сортировки
				$picturesSort[$newFile] = $file_name;
				$picturesFile[$newFile] = CFile::MakeFileArray($newFile);
			}

			// сортируем список по имени полученных файлов для MORE_PHOTO и обновляем свойства
			$arrProps = [];
			asort($picturesSort);
			foreach ($picturesSort as $id => $name) {
				$picturesFile[$id]['name'] = $name;
				$arrProps[] = ['VALUE' => $picturesFile[$id], 'DESCRIPTION' => $picturesFile[$id]['description']];
			}
			// обновляем доп картинки с удалением старых
			if ($arrProps) {
				CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, [PROP_MORE_PHOTO => $arrProps]);
				foreach ($picturesSort as $id => $name) CFile::Delete($id);
			}
		}
	}

	/** тест импорта 1с */
	function OnSuccessCatalogImport1C($arParams, $file) {
		// if ($_REQUEST['mode'] == 'import' && mb_strstr($_REQUEST['filename'], 'import')) {
		// }
	}
}
