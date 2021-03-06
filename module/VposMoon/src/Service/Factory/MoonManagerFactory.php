<?php

namespace VposMoon\Service\Factory;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zend\Session\SessionManager;
use Zend\Session\Container;
use VposMoon\Service\MoonManager;

/**
 * Description of MoonManagerFactory
 *
 * @author chr
 */
class MoonManagerFactory {

	/**
	 * This method creates the UserManager service and returns its instance. 
	 */
	public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
	{
		$sessionManager = $container->get(SessionManager::class);
		$sessionContainer = new Container('eve_user', $sessionManager);

	
		return new MoonManager(
			$sessionContainer,
			$container->get('doctrine.entitymanager.orm_default'),
			$container->get(\User\Service\EveSSOManager::class),
			$container->get(\Application\Service\EveEsiManager::class),
			$container->get('MyLogger')
		);
	}

}
