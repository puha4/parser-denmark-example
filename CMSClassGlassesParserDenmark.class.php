<?php

require_once('./engine/simple_html_dom.php');

class CMSClassGlassesParserDenmark extends CMSClassGlassesParser {
	const URL_BASE = 'http://www.prodesigndenmark.com';
	const URL_LOGIN = 'http://www.prodesigndenmark.com/en-US/eShop-Prodesign-Denmark.aspx';

	private $_category_links = array(
		'frames' => 'http://www.prodesigndenmark.com/en-US/frames.aspx?GroupID=&PageNum=%d',
		'sun' => 'http://www.prodesigndenmark.com/en-US/sun.aspx?GroupID=&PageNum=%d',
	);

	/**
	 * @return CMSLogicProvider id for current provider
	 */
	public function getProviderId() {
		return CMSLogicProvider::DENMARK;
	}

	/**
	 * Login to account on site
	 */
	public function doLogin() {
		$http = $this->getHttp();

		$post = array (
			'Username' => $this->getUsername(),
			'Password' => $this->getPassword(),
			'ID' => '1',
		);
		$http->doPost(self::URL_LOGIN, $post);
	}
	/**
	 * Check login done
	 * @return boolean           [true if login done]
	 */
	public function isLoggedIn($contents) {
		return strpos($contents, '<span class="sr-only">Sign Out</span>') !== false;
	}

	/**
	 * Синхронизация брендов
	 */
	public function doSyncBrands() {
		$brands[strtoupper('PD')] = array('name' => 'PRODESIGN');
		if (!$brands) {
			throw new CMSException();
		}

		$myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());
		$coded = array();

		foreach($myBrands as $b) {
			if ($b instanceof CMSTableBrand) {
				$coded[$b->getCode()] = $b;
			}
		}

		foreach($brands as $code => $info) {
			if (!isset($coded[$code])) {
				CMSLogicBrand::getInstance()->create($this->getProvider(), $info['name'], $code, '');
			}
		}

	}

	/**
	 * Sync items on category page (get urls)
	 */
	public function doSyncItems() {
		$product_url = '';
		$count = 0;
		$all_count = 0;
		$links = array();

		$http = $this->getHttp();

		$brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());
		$brand = $brands[0];

		if($brand instanceof CMSTableBrand) {
			if($brand->getValid()) {
				echo get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
			} else {
				echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
				continue;
			}
		} else {
			throw new Exception('Brand not CMSTableBrand instance');
		}

		// Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
		$this->resetModelByBrand($brand);
		// Сбрасываем сток для моделей
		$this->resetStockByBrand($brand);

		$category_links = $this->_category_links;

		foreach($category_links as $type => $link) {
			for($i = 1; true; $i++) {
				$product_url = sprintf($link, $i);
				$http->doGet($product_url);
				$content = $http->getContents();

				$dom = str_get_html($content);
				$items_selector = '.product-list-item .carousel-inner .active';
				$items = $dom->find($items_selector);

				// Если товары закончились переходим к следующей категории
				// условие важно так как это единственный выход из бесконечного цикла
				if(!count($items)) {
					break;
				}

				// чтобы долго не ждать при отладке
				// if($i == 2) {
				// 	break;
				// }

				$count = count($items);
				$all_count += $count;

				echo "Get links for {$count} ({$all_count}) {$type} products on {$i} page.\n";

				foreach($items as $key => $item) {
					$cur_item_href = $item->find('.EcomProductLink');
					$cur_item_stock = $item->find('.EcomProductStock');
					$cur_item_name = $item->find('.EcomProductName');
					$cur_item_price = $item->find('.EcomProductPrice');

					$href = trim($cur_item_href[0]->href);
					$stock = trim($cur_item_stock[0]->plaintext);
					$name = trim($cur_item_name[0]->plaintext);
					$price = trim($cur_item_price[0]->plaintext);

					$href = htmlspecialchars_decode(self::URL_BASE . $href);

					$links[$type][] = [
						'href' 	=> $href,
						'stock' => $stock,
						'name' => $name,
						'price' => $price,
					];
				}
			}
		}

		// перходим к парсингу страничек продуктов
		foreach($links as $type => $type_links) {
			foreach ($type_links as $key => $link) {
				$this->parsePageItems($link['href'],$type, $brand);
			}
		}
	}

	/**
	 * Парсим модель по ее ссылке
	 * @param  string        $href  [ссылка]
	 * @param  string        $type  [тип (frames or sun)]
	 * @param  CMSTableBrand $brand [бренд]
	 */
	private function parsePageItems($href, $type, CMSTableBrand $brand) {
		$result = array();
		$http = $this->getHttp();

		$http->doGet($href);
		$content = $http->getContents();

		$dom = str_get_html($content);

		$items_selector = '#carousel-variants .slides li';
		$items = $dom->find($items_selector);

		// Если товары закончились переходим к следующей категории
		// условие важно так как это единственный выход из бесконечного цикла
		if(!count($items)) {
			echo "It no item in carousel ({$href})!!!";
			return;
		}

		foreach($items as $key => $item) {
			// preg_match("/(\d+).*c\.[ ]*(\d+).*([\d]{2,}\/\d+).*?[ ](.+)/i", $item->title, $matches);
			// из атрибута li[title] строки выделяем основные свойства для модели с помощью регулярки
			// что бы было проще с регуляркой убираем некоторые ненужные фрагменты в строке
			$title = str_replace('w/nosepads', '', $item->title);
			preg_match("/(\d+).*[c|C]\.[ ]*(\d+).*?([A-Za-z].*)/", $title, $matches);
			$item_title_code = trim($matches[1]);
			$color_code = trim($matches[2]);
			// $sizes = trim($matches[3]);
			$color_title = trim($matches[3]);
			$item_title = "Model " . $item_title_code;

			//достаем размеры
			$sizes_selector = "#carousel-detail-3 .carousel-inner div[id={$item->id}] h4 span";
			$cur_item_sizes = $dom->find($sizes_selector);

			if(!count($cur_item_sizes)) {
				echo "No sizes for current item ({$item_title_code}).\n";
				continue;
			}

			$sizes = trim($cur_item_sizes[0]->plaintext);
			$sizes_arr = explode('&#9744;', $sizes);
			$size_1 = $sizes_arr[0];
			$size_2 = $sizes_arr[1];
			$size_3 = 140;

			// достаем ссылку на изображение
			$cur_item_image = $item->find('img.img-responsive');

			if(!count($cur_item_image)) {
				echo "No image for current item ({$item_title_code}).\n";
				continue;
			}

			$item_image = htmlspecialchars_decode(self::URL_BASE . $cur_item_image[0]->src);

			// достаем сток статус
			$stock_selector = ".stockinfo .carousel-inner div[id={$item->id}]";
			$cur_item_stock = $dom->find($stock_selector);

			if(!count($cur_item_stock)) {
				echo "No stock for current item ({$item_title_code}).\n";
				continue;
			}

			$item_stock = trim($cur_item_stock[0]->plaintext);

			// отсекаем те которые out of stock
			if($item_stock !== "In Stock") {
				continue;
			}

			// определяем тип очков
			if($type === "frames") {
				$typeItem = CMSLogicGlassesItemType::getInstance()->getEye();
			} else {
				$typeItem = CMSLogicGlassesItemType::getInstance()->getSun();
			}

			// достаем цену
			$price_selector = "#carousel-detail-1 .carousel-inner div[id={$item->id}] .pull-left";
			$cur_item_price = $dom->find($price_selector);
			$item_price = trim(str_replace('$', '', $cur_item_price[0]->plaintext));

			// небольшой лог
			echo "\nurl          - " . $href."\n";
			echo "item title   - " . $item_title. "\n";
			echo "item ext id  - " . $item_title_code. "\n";
			echo "color code   - " . $color_code. "\n";
			echo "type         - " . $type. "\n";
			echo "item sizes   - " . str_replace("&#9744;", '/', $sizes). "\n";
			echo "color title  - " . $color_title. "\n";
			echo "item image   - " . $item_image. "\n";
			echo "stock        - " . $item_stock. "\n";
			echo "price        - " . $item_price . "\n";
			echo "==================================================================\n";

			// создаем обьект модели и синхронизируем
			$item = new CMSClassGlassesParserItem();
			$item->setBrand($brand);
			$item->setExternalId($item_title_code);
			$item->setType($typeItem);
			$item->setTitle($item_title);
			$item->setColor($color_title);
			$item->setColorCode($color_code);
			$item->setStockCount(1);
			$item->setPrice($item_price);
			$item->setImg($item_image);
			$item->setSize($size_1);
			$item->setSize2($size_2);
			$item->setSize3($size_3);
			$item->setIsValid(1);

			$result[] = $item;
		}
		$dom->clear();

		foreach($result as $res) {
			$res->sync();
		}
	}
}