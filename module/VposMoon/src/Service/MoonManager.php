<?php

namespace VposMoon\Service;

use VposMoon\Entity\AtMoon;
use VposMoon\Entity\AtMoongoo;
use Seat\Eseye\Eseye;

/**
 * The MoonManager manages all list/read/write/delete operations about Moons and Moon Goo
 */
class MoonManager {

	/**
	 * Doctrine entity manager.
	 * @var Doctrine\ORM\EntityManager
	 */
	private $entityManager;

	/**
	 *
	 * @var \Application\Service\EveEsiManager
	 */
	private $eveESIManager;

	/**
	 *
	 * @var \Application\Controller\Plugin\LoggerPlugin
	 */
	private $logger;

	/**
	 *
	 * @var \User\Service\EveSSOManager
	 */
	private $eveSSOManager;

	/**
	 *
	 * @var \Zend\Session\Container
	 */
	private $sessionContainer;

	/**
	 * Regular Expression to identify a Moon/Survey Scan Headline
	 * @var string
	 */
	private $moon_scan_headline_regexp = '/^Moon\s+Moon Product\s+Quantity\s+Ore TypeID\s+SolarSystemID\s+PlanetID\s+MoonID$/';

	/**
	 * Regular Expression to identify a Moon/Survey line with the Moon name
	 * @var string
	 */
	private $moon_scan_moonline_regexp = '/^[a-zA-Z0-9\-]+\b\s+[IVX]{1,4}\s-\sMoon\b\s[1-9]{1,2}/';

	/**
	 * Regular Expression to identify a Moon/Survey data-line (that's what we're looking for)
	 * @var string
	 */
	private $moon_scan_gooline_regexp = '/^\s+[A-Za-z ]+\s+(0\.[0-9]+)\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)/';

	/**
	 * To collect moon goo data
	 * @var array
	 */
	private $data_collector;

	/**
	 *
	 * @var int
	 */
	private $eve_userid = 0;

	/**
	 * Constructs the service.
	 */
	public function __construct($sessionContainer, $entityManager, $eveSSOManager, $eveESIManager, $logger)
	{
		$this->sessionContainer = $sessionContainer;
		$this->entityManager = $entityManager;
		$this->eveSSOManager = $eveSSOManager;
		$this->eveESIManager = $eveESIManager;
		$this->logger = $logger;
	}

	/**
	 * Check if Scan is a MoonSurvey Scan - collect Moon Goo data
	 * 
	 * Splits a multiline string and check if the first line is a survey/moon scanner
	 * header line aganist a regex @see $isMoonScanRegexp.
	 * 
	 * If match the data is collected in $this->collectGoo.
	 * 
	 * @param string $line
	 * @param string $eve_user
	 * @return boolean
	 */
	public function isMoonScan($line, $eve_user)
	{
		$this->eve_userid = $eve_user;

		if (preg_match($this->moon_scan_headline_regexp, $line) || preg_match($this->moon_scan_moonline_regexp, $line)) {
			return(true);
		} else if (preg_match($this->moon_scan_gooline_regexp, $line, $matches)) {
			$this->collectGoo($matches['1'], $matches['2'], $matches['5']);
			return(true);
		}
		return(false);
	}

	/**
	 * Process the data collected - stores Moon Goo data
	 */
	public function processScan()
	{
		if (!empty($this->data_collector)) {
			foreach ($this->data_collector as $moon => $goo) {
				// assure the AtMoon entry exist to add goo to him
				$moon_id = $this->writeMoon($moon);
				$this->persistMoonGoo($moon_id, $moon, $goo);
			}
		}
	}

	/**
	 * Collect Moon Goo data
	 * 
	 * @param float $qty
	 * @param int $ore_typeid
	 * @param int $moon_id
	 */
	private function collectGoo($qty, $ore_typeid, $moon_id)
	{
		$this->data_collector[$moon_id][$ore_typeid] = $qty;
	}

	/**
	 * Create an new AtMoon 
	 * if existing only the lastseen is updated.
	 * 
	 * @param int $moon_id
	 */
	private function writeMoon($moon_id)
	{
		$moon_entity = $this->entityManager->getRepository(AtMoon::class)->findOneByEveMapdenormalizeItemid($moon_id);

		// insert (or update)
		if ($moon_entity === null) {
			$moon_entity = new AtMoon();
			$moon_entity->setnamedStructure('');
			$moon_entity->setownedBy(0);
			$moon_entity->seteveInvtypesTypeid($this->entityManager->getRepository(\Application\Entity\Invtypes::class)->findOneByTypeid(0));
			$moon_entity->setCreateDate(new \DateTime("now"));
			$moon_entity->setCreatedBy($this->eve_userid);
			$moon_entity->setEveMapdenormalizeItemid($this->entityManager->getRepository(\Application\Entity\Mapdenormalize::class)->findOneByItemid($moon_id));
		}

		$moon_entity->setLastseenDate(new \DateTime("now"));
		$moon_entity->setLastseenBy($this->eve_userid);

		$this->entityManager->persist($moon_entity);
		$this->entityManager->flush();

		return($moon_entity->getMoonId());
	}

	/**
	 * Read Moon Goo from a scan and persist it to the database
	 * 
	 * @param int $moon_id
	 * @param array $goo_data
	 */
	private function persistMoonGoo($moon_id, $moon, $goo_data)
	{
		foreach ($goo_data as $goo_id => $qty) {
			$this->writeMoonGoo($moon_id, $goo_id, $qty);
		}
	}

	/**
	 * Persist Moon Goo
	 * @see persistMoonGoo()
	 * 
	 * @param int $moon_id
	 * @param int $goo_id
	 * @param int $qty
	 * @return void
	 */
	private function writeMoonGoo($moon_id, $goo_id, $qty)
	{
		// get the goo from the Eve invtypes
		$invtypes_entity = $this->entityManager->getRepository(\Application\Entity\Invtypes::class)->findOneByTypeid($goo_id);
		if ($invtypes_entity === null) {
			$this->logger->info('got Goo with unknown TypeID: __' . $goo_id . '__');
			return;
		}

		$moongoo_entity = $this->entityManager->getRepository(AtMoongoo::class)->findOneBy(array('moon' => $moon_id, 'eveInvtypesTypeid' => $goo_id));

		// insert (or update)
		if ($moongoo_entity === null) {
			$moongoo_entity = new AtMoongoo();
			$moongoo_entity->setCreateDate(new \DateTime("now"));
			$moongoo_entity->setCreatedBy($this->eve_userid);
			$moongoo_entity->setEveInvtypesTypeid($invtypes_entity);
			$moongoo_entity->setMoon($this->entityManager->getRepository(AtMoon::class)->findOneByMoonId($moon_id));
		}

		$moongoo_entity->setGooAmount((float) $qty);
		$moongoo_entity->setLastseenBy($this->eve_userid);
		$moongoo_entity->setLastseenDate(new \DateTime("now"));

		$this->entityManager->persist($moongoo_entity);
		$this->entityManager->flush();
	}

	/**
	 * create list of moons according to filter settings
	 * 
	 * @param array $filters
	 * @return array
	 */
	public function moonList($filters)
	{
		$query = $this->entityManager->getConnection()->exec('SET @@group_concat_max_len = 8000;');


		$queryBuilder = $this->entityManager->createQueryBuilder();

		$queryBuilder->select('m, sum(mg.gooAmount) as ga, uchgd.eveUsername as uchgddat')
			->addSelect("GROUP_CONCAT(DISTINCT mg.gooAmount, '|', it.typename , '|', it.baseprice order by mg.gooAmount desc SEPARATOR '#') as goo")
			->addSelect("SUM(itmt.baseprice * itm.quantity) as oreval")
			->addSelect("GROUP_CONCAT(DISTINCT itmt.typename) as hasmaterial")
			->addSelect("GROUP_CONCAT(IDENTITY(mg.eveInvtypesTypeid), '|', mg.gooAmount, '|', it.typename, '|',  itmt.typeid, '|', itm.quantity, '|', itmt.typename, '|', itmt.baseprice SEPARATOR '#') as materiallist")
			->from(\VposMoon\Entity\AtMoon::class, 'm')
			->leftJoin(\VposMoon\Entity\AtMoongoo::class, 'mg', 'WITH', 'm.moonId = mg.moon')
			->leftJoin(\Application\Entity\Invtypes::class, 'it', 'WITH', 'it.typeid = mg.eveInvtypesTypeid')
			->leftJoin(\Application\Entity\Mapdenormalize::class, 'md', 'WITH', 'md.itemid = m.eveMapdenormalizeItemid')
			->leftJoin(\Application\Entity\Invtypematerials::class, 'itm', 'WITH', 'itm.typeid = mg.eveInvtypesTypeid')
			->leftJoin(\Application\Entity\Invtypes::class, 'itmt', 'WITH', 'itmt.typeid = itm.materialtypeid')
			->leftJoin(\User\Entity\User::class, 'uchgd', 'WITH', 'm.lastseenBy = uchgd.eveUserid')
			->groupBy('m.moonId')
			->having('hasmaterial is not null');

		/*
		 * now add the filters to the query
		 */
		
		// first run: collect parameters
		$parameter = null;
		if (!empty($filters['ore'])) {
			$parameter['oreid'] = $filters['ore'];
		} else if (!empty($filters['composition'])) {
			$parameter['compositionid'] = $filters['composition'];
		}
		if (!empty($filters['system'])) {
			$parameter['mditemid'] = $filters['system'];
		}
		if (!empty($parameter)) {
			$queryBuilder->setParameters($parameter);
		}

		// second run: add where conditions
		// exclusive filter, either "ore" or "composition"
		if (!empty($filters['ore'])) {
			$queryBuilder->andWhere('itm.materialtypeid = :oreid');
		} else if (!empty($filters['composition'])) {
			$queryBuilder->andWhere('mg.eveInvtypesTypeid = :compositionid');
		}
		if (!empty($filters['system'])) {
			$queryBuilder->andWhere('md.solarsystemid = :mditemid or md.constellationid = :mditemid');
		}

		$res = $queryBuilder->getQuery()->getResult();
		return($res);
	}

	/**
	 * Manage filters for @see moonList
	 * 
	 * @param array $get_parameters
	 * @param \Application\Service\EveDataManager $eveDataManager
	 * @return array
	 */
	public function manageFilters($get_parameters, $eveDataManager)
	{
		if (!empty($get_parameters['forget'])) {
			unset($this->sessionContainer->filter);
		}

		// restore filter from user session
		if (empty($this->sessionContainer->filter)) {
			$filters = array();
		} else {
			$filters = $this->sessionContainer->filter;
		}

		if (!empty($get_parameters['system'])) {
			$filters['system'] = $get_parameters['system'];
		}

		if (isset($get_parameters['composition'])) {
			if ($get_parameters['composition'] == '0') {
				$filters['composition'] = 0;
			} else {
				$filters['composition'] = $get_parameters['composition'];
				$filters['ore'] = 0;
			}
		}

		if (isset($get_parameters['ore'])) {
			if ($get_parameters['ore'] == '0') {
				$filters['ore'] = 0;
			} else {
				$filters['ore'] = $get_parameters['ore'];
				$filters['composition'] = 0;
			}
		}

		// if no system is give fix it to Jita
		if (empty($filters['system'])) {
			$my_location = $this->eveSSOManager->getUserLocationAsSystemID();
			// Current location if given, otherwise we'll take Jita
			$filters['system'] = ($my_location ? $my_location : '30000142');
		}
		$filters['info_system'] = $eveDataManager->getSystemByID($filters['system'])[0];


		// persist filter into user session
		$this->sessionContainer->filter = $filters;
		return($filters);
	}

	/**
	 * Get array of Moon Goo available in a solar system or constellation
	 * 
	 * @param string $solar
	 * @return array
	 */
	public function getCompositionList($solar)
	{
		$queryBuilder = $this->entityManager->createQueryBuilder();

		$queryBuilder->select('it.typeid as id, it.typename as name, count(distinct mg.moon) as cnt')
			->from(\VposMoon\Entity\AtMoongoo::class, 'mg')
			->leftJoin(\VposMoon\Entity\AtMoon::class, 'm', 'WITH', 'm.moonId = mg.moon')
			->leftJoin(\Application\Entity\Invtypes::class, 'it', 'WITH', 'it.typeid = mg.eveInvtypesTypeid')
			->leftJoin(\Application\Entity\Mapdenormalize::class, 'md', 'WITH', 'md.itemid = m.eveMapdenormalizeItemid')
			->groupBy('mg.eveInvtypesTypeid')
			->orderBy('it.typename');

		if (!empty($solar)) {
			$queryBuilder->where('md.solarsystemid = :mditemid or md.constellationid = :mditemid')->setParameters(array('mditemid' => $solar));
		}

		$res = $queryBuilder->getQuery()->getResult();

		return($res);
	}

	/**
	 * Get array of Moon Ores (after refining the goo) available in a solar system or constellation
	 * 
	 * @param string $solar
	 * @return array
	 */
	public function getOreList($solar)
	{
		$queryBuilder = $this->entityManager->createQueryBuilder();

		$queryBuilder->select('it.typeid as id, it.typename as name, count(distinct mg.moon) as cnt')
			->from(\VposMoon\Entity\AtMoongoo::class, 'mg')
			->leftJoin(\VposMoon\Entity\AtMoon::class, 'm', 'WITH', 'm.moonId = mg.moon')
			->leftJoin(\Application\Entity\Invtypematerials::class, 'itm', 'WITH', 'itm.typeid = mg.eveInvtypesTypeid')
			->leftJoin(\Application\Entity\Invtypes::class, 'it', 'WITH', 'it.typeid = itm.materialtypeid')
			->leftJoin(\Application\Entity\Mapdenormalize::class, 'md', 'WITH', 'md.itemid = m.eveMapdenormalizeItemid')
			->groupBy('it.typeid')
			->orderBy('it.typename');

		if (!empty($solar)) {
			$queryBuilder->where('md.solarsystemid = :mditemid or md.constellationid = :mditemid')->setParameters(array('mditemid' => $solar));
		}

		$res = $queryBuilder->getQuery()->getResult();

		return($res);
	}

}
