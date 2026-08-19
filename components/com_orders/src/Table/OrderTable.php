<?php

namespace Viglin\Component\Orders\Site\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

class OrderTable extends Table
{
	public function __construct(DatabaseInterface $db, DispatcherInterface $dispatcher = null)
	{
		parent::__construct('#__vigling_bookings', 'id', $db, $dispatcher);
	}
}
