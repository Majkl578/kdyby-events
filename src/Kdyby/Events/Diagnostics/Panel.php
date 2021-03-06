<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Events\Diagnostics;

use Doctrine\Common\EventArgs;
use Kdyby;
use Kdyby\Events\Event;
use Kdyby\Events\EventManager;
use Nette;
use Nette\Diagnostics\Bar;
use Nette\Diagnostics\Debugger;
use Nette\Iterators\CachingIterator;
use Nette\Utils\Arrays;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class Panel extends Nette\Object implements Nette\Diagnostics\IBarPanel
{

	/**
	 * @var EventManager
	 */
	private $evm;

	/**
	 * @var Nette\DI\Container
	 */
	private $sl;

	/**
	 * @var array
	 */
	private $events = array();

	/**
	 * @var array
	 */
	private $dispatchLog = array();

	/**
	 * @var array
	 */
	private $listenerIds = array();

	/**
	 * @var array
	 */
	private $inlineCallbacks = array();

	/**
	 * @var array
	 */
	private $registeredClasses;



	public function __construct(Nette\DI\Container $sl)
	{
		$this->sl = $sl;
	}



	/**
	 * @param EventManager $evm
	 */
	public function setEventManager(EventManager $evm)
	{
		$this->evm = $evm;
		$evm->setPanel($this);
	}



	public function setServiceIds(array $listenerIds)
	{
		$this->listenerIds = $listenerIds;
	}



	public function registerEvent(Event $event)
	{
		$this->events[] = $event;
		$event->setPanel($this);
	}



	public function eventDispatch($eventName, EventArgs $args = NULL)
	{
		$this->dispatchLog[$eventName][] = $args;
	}



	public function inlineCallbacks($eventName, $inlineCallbacks)
	{
		$this->inlineCallbacks[$eventName] = (array) $inlineCallbacks;
	}



	/**
	 * Renders HTML code for custom tab.
	 *
	 * @return string
	 */
	public function getTab()
	{
		if (empty($this->events)) {
			return NULL;
		}

		return '<span title="Kdyby/Events">'
		. '<img width="16" height="16" src="data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/icon.png')) . '" />'
		. count(Arrays::flatten($this->dispatchLog)) .  ' calls'
		. '</span>';
	}



	/**
	 * Renders HTML code for custom panel.
	 *
	 * @return string
	 */
	public function getPanel()
	{
		if (empty($this->events)) {
			return NULL;
		}

		$visited = array();

		$h = 'htmlspecialchars';
		$s = '';

		foreach ($it = new CachingIterator($this->dispatchLog) as $eventName => $calls) {
			if (!$it->isFirst()) {
				$s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
			}
			$s .= '<tr><th colspan=2>' . count($calls) . 'x ' . $h($eventName) . '</th></tr>';
			$visited[] = $eventName;

			$s .= $this->renderListeners($this->getInlineCallbacks($eventName));

			if (empty($this->listenerIds[$eventName])) {
				$s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';

			} else {
				$s .= $this->renderListeners($this->listenerIds[$eventName]);
			}

			$s .= $this->renderCalls($calls);
		}

		foreach ($it = new CachingIterator($this->events) as $event) {
			/** @var Event $event */
			if (in_array($event->getName(), $visited, TRUE)) {
				continue;
			}

			$calls = $this->getEventCalls($event->getName());
			$s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
			$s .= '<tr><th colspan=2>' . count($calls) . 'x ' . $h($event->getName()) . '</th></tr>';
			$visited[] = $event->getName();

			$s .= $this->renderListeners($this->getInlineCallbacks($event->getName()));

			if (empty($this->listenerIds[$event->getName()])) {
				$s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';

			} else {
				$s .= $this->renderListeners($this->listenerIds[$event->getName()]);
			}

			$s .= $this->renderCalls($calls);
		}

		foreach ($it = new CachingIterator($this->listenerIds) as $eventName => $ids) {
			if (in_array($eventName, $visited, TRUE)) {
				continue;
			}

			$calls = $this->getEventCalls($eventName);
			$s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
			$s .= '<tr><th colspan=2>' . count($calls) . 'x ' . $h($eventName) . '</th></tr>';

			$s .= $this->renderListeners($this->getInlineCallbacks($eventName));

			if (empty($ids)) {
				$s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';

			} else {
				$s .= $this->renderListeners($ids);
			}

			$s .= $this->renderCalls($calls);
		}

		$totalEvents = count($this->listenerIds);
		$totalListeners = count(array_unique(Arrays::flatten($this->listenerIds)));

		return '<style>' . $this->renderStyles() . '</style>'.
			'<h1>' . $h($totalEvents) . ' registered events, ' . $h($totalListeners) . ' registered listeners</h1>' .
			'<div class="nette-inner nette-KdybyEventsPanel"><table>' . $s . '</table></div>';
	}



	private function getEventCalls($eventName)
	{
		return !empty($this->dispatchLog[$eventName]) ? $this->dispatchLog[$eventName] : array();
	}



	private function getInlineCallbacks($eventName)
	{
		return !empty($this->inlineCallbacks[$eventName]) ? $this->inlineCallbacks[$eventName] : array();
	}



	private function renderListeners($ids)
	{
		static $addIcon;
		if (empty($addIcon)) {
			$addIcon = '<img width="18" height="18" src="data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/add.png')) . '" title="Listener" />';
		}

		$registeredClasses = $this->getClassMap();

		$h = 'htmlspecialchars';
		$s = '';
		foreach ($ids as $id) {
			if ($id instanceof Nette\Callback) {
				$s .= '<tr><td width=18>' . $addIcon . '</td><td><pre class="nette-dump"><span class="nette-dump-object">' .
					(string) $id .
					'</span></span></th></tr>';

				continue;
			}

			if (!$this->sl->isCreated($id) && ($class = array_search($id, $registeredClasses, TRUE))) {
				$s .= '<tr><td width=18>' . $addIcon . '</td><td><pre class="nette-dump"><span class="nette-dump-object">' .
					$h(Nette\Reflection\ClassType::from($class)->getName()) .
					'</span></span></th></tr>';

			} else {
				$s .= '<tr><td width=18>' . $addIcon . '</td><td>' . self::dumpToHtml($this->sl->getService($id)) . '</th></tr>';
			}
		}

		return $s;
	}



	private static function dumpToHtml($structure)
	{
		if (class_exists('Nette\Diagnostics\Dumper')) {
			return Nette\Diagnostics\Dumper::toHtml($structure, array(Nette\Diagnostics\Dumper::COLLAPSE => TRUE));
		}

		return Nette\Diagnostics\Helpers::clickableDump($structure, TRUE);
	}



	private function getClassMap()
	{
		if ($this->registeredClasses !== NULL) {
			return $this->registeredClasses;
		}

		if (property_exists('Nette\DI\Container', 'classes')) {
			return $this->registeredClasses = $this->sl->classes;
		}

		$refl = new Nette\Reflection\Property('Nette\DI\Container', 'meta');
		$refl->setAccessible(TRUE);
		$meta = $refl->getValue($this->sl);

		$this->registeredClasses = array();
		foreach ($meta['types'] as $type => $serviceIds) {
			if (isset($this->registeredClasses[$type])) {
				$this->registeredClasses[$type] = FALSE;
				continue;
			}

			$this->registeredClasses[$type] = $serviceIds;
		}

		return $this->registeredClasses;
	}



	private function renderCalls(array $calls)
	{
		static $runIcon;
		if (empty($runIcon)) {
			$runIcon = '<img width="18" height="18" src="data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/run.png')) . '" title="Event dispatch" />';
		}

		$s = '';
		foreach ($calls as $args) {
			$s .= '<tr><td width=18>' . $runIcon . '</td><td>' . ($args ? self::dumpToHtml($args) : 'dispatched without arguments') . '</th></tr>';
		}

		return $s;
	}



	/**
	 * @return string
	 */
	protected function renderStyles()
	{
		return <<<CSS
			#nette-debug .nette-panel .nette-KdybyEventsPanel { width: 670px !important; }
			#nette-debug .nette-panel .nette-KdybyEventsPanel table { width: 655px !important; }
			#nette-debug .nette-panel .nette-KdybyEventsPanel table th { font-size: 16px; }
			#nette-debug .nette-panel .nette-KdybyEventsPanel table tr td:first-child { padding-bottom: 0; }
			#nette-debug .nette-panel .nette-KdybyEventsPanel table tr.blank td { background: white; height:25px; border-left:0; border-right:0; }
CSS;
	}



	/**
	 * @param EventManager $eventManager
	 * @param \Nette\DI\Container $sl
	 * @return Panel
	 */
	public static function register(EventManager $eventManager, Nette\DI\Container $sl)
	{
		$panel = new static($sl);
		/** @var Panel $panel */

		$panel->setEventManager($eventManager);
		static::getDebuggerBar()->addPanel($panel);

		return $panel;
	}



	/**
	 * @return Bar
	 */
	private static function getDebuggerBar()
	{
		return method_exists('Nette\Diagnostics\Debugger', 'getBar') ? Debugger::getBar() : Debugger::$bar;
	}

}
