<?php

namespace Viglin\Component\Viglingservices\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Form\Form;

class ServiceModel extends AdminModel
{
    protected $text_prefix = 'COM_VIGLINGSERVICES';

    public function getForm($data = [], $loadData = true)
    {
        return $this->loadForm('com_viglingservices.service', 'service', ['control' => 'jform', 'load_data' => $loadData]);
    }

    protected function loadFormData()
    {
        $app = Factory::getApplication();
        $data = $app->getUserState('com_viglingservices.edit.service.data', []);
        if (empty($data)) {
            $data = $this->getItem();
        }
        return $data;
    }

    protected function preprocessForm(Form $form, $data, $group = 'content')
    {
        parent::preprocessForm($form, $data, $group);

        $id = 0;
        if (is_object($data) && isset($data->id)) {
            $id = (int) $data->id;
        } elseif (is_array($data) && isset($data['id'])) {
            $id = (int) $data['id'];
        }

        $query = "SELECT id AS value, CONCAT(path, ' - ', title) AS text FROM #__vigling_service_nodes";
        if ($id > 0) {
            $query .= " WHERE id <> " . $id;
        }
        $query .= " ORDER BY path";

        $form->setFieldAttribute('parent_id', 'query', $query);
    }
}
