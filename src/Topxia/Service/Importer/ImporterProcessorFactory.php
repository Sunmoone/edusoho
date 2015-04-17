<?php
namespace Topxia\Service\Importer;

use Topxia\Service\Importer\ImporterProcessor;

class ImporterProcessorFactory
{

	public static function create($target)
    {
    	if(empty($target) || !in_array($target,array('classroomUser'))) {
    		throw new Exception("用户导入类型不存在");
    	}

    	$class = __NAMESPACE__ . '\\' . ucfirst($target). 'ImporterProcessor';

    	return new $class();
    }

}


