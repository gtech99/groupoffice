<?php


namespace go\modules\community\tasks\install;

use go\core\model\User;
use go\modules\community\tasks\model\Tasklist;
use GO\Projects2\Model\ProjectEntity;
use go\core\util\DateTime;

class Migrator
{
	public function job2task()
	{
		echo "Migrating project jobs to tasks" . PHP_EOL . PHP_EOL;
		// foreach pr2_tasks record:
		$counter = 0;
		go()->getDbConnection()->beginTransaction();
		$query = go()->getDbConnection()
				->select('*')
				->from('pr2_tasks');
        $stmt = $query->execute();

        while($record = $stmt->fetch()) {
        	$counter++;
        	$jobId = $record['id'];

        	$tasklistId = null;
	        if($record['project_id'] > 0) {
		        $projectId = $record['project_id'];
		        $project = ProjectEntity::findById($projectId);
		        // If for some reason there are more task lists, just select the first task list
		        $prt = go()->getDbConnection()
			        ->select('id')
			        ->from('tasks_tasklist')
			        ->where('project_id = ' . $projectId)
			        ->single();
		        if($prt) {
		        	$tasklistId = $prt['tasklist_id'];
		        } else {
		        	$arFlds = [
		        		'role' => Tasklist::Project,
				        'name' => $project->name,
				        'createdBy'=> $project->user_id,
				        'aclId' => $project->findAclId(),
				        'projectId' => $projectId,
				        'version'=> 1,
				        'ownerId' => $project->user_id
			        ];
		        	go()->getDbConnection()->insert('tasks_tasklist', $arFlds)->execute();
		        	$tasklistId = go()->getDbConnection()->getPDO()->lastInsertId();
		        }
	        } else {
	        	$counter++;
	        	echo 'S';
		        if($counter % 50 === 0 ) {
			        echo PHP_EOL;
		        }
	        	continue;
	        }
	        $due = $record['due_date'];
	        if(!empty($due)) {
	        	$ts = new DateTime();
	        	$ts->setTimestamp($due);
	        }
        	$arFlds = [
        		'uid' => \go\core\util\UUID::v4(),
        		'tasklistId' => $tasklistId,
		        'responsibleUserId' => $record['user_id'],
		        'percentComplete' => $record['percentage_complete'],
		        'estimatedDuration' => $record['duration'],
		        'createdBy' => User::ID_SUPER_ADMIN,
		        'createdAt' => new DateTime(),
		        'due' => !empty($due) ? $ts : null,
		        'title' => $record['description'],
		        'description' => ''
	        ];
        	if(!go()->getDbConnection()->insert('tasks_task', $arFlds)->execute()) {
        		throw new \Exception("Que?");
	        };
        	$taskId = go()->getDbConnection()->getPDO()->lastInsertId();

        	go()->getDbConnection()->update('pr2_hours', ['task_id' => $taskId], ['task_id' => $jobId])->execute();
	        echo '.';
	        if($counter % 50 === 0 ) {
	        	echo PHP_EOL;
	        }
        }

		go()->getDbConnection()->commit();
		echo PHP_EOL . PHP_EOL . 'Done migrating project jobs to tasks';
	}
}