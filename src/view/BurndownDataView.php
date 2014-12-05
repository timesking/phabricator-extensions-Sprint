<?php
/**
 * @author Michael Peters
 * @license GPL version 3
 */

final class BurndownDataView extends SprintView
{

  private $request;
  private $timeseries;
  private $sprint_data;
  private $project;
  private $viewer;
  private $tasks;
  private $events;
  private $xactions;
  private $start;
  private $end;
  private $before;

  public function setProject($project) {
    $this->project = $project;
    return $this;
  }

  public function setViewer($viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRequest($request) {
    $this->request = $request;
    return $this;
  }

  public function render() {
    $chart = $this->buildC3Chart();
    $tasks_table = id(new TasksTableView())
        ->setProject($this->project)
        ->setViewer($this->viewer)
        ->setRequest($this->request);
    $tasks_table = $tasks_table->buildTasksTable();
    $pie = $this->buildC3Pie();
    $history_table = new HistoryTableView();
    $history_table = $history_table->buildHistoryTable($this->before);
    $sprint_table = new SprintTableView();
    $burndown_table = $sprint_table->buildBurnDownTable($this->sprint_data, $this->before);
    $event_table = id(new EventTableView())
        ->setProject($this->project)
        ->setViewer($this->viewer)
        ->setRequest($this->request);
    $event_table = $event_table->buildEventTable($this->events, $this->xactions,
        $this->tasks, $this->start, $this->end);
    return array($chart, $tasks_table, $pie, $history_table, $burndown_table, $event_table);
  }

  private function buildChartDataSet() {
    $query = id(new SprintQuery())
        ->setProject($this->project)
        ->setViewer($this->viewer);
    $aux_fields = $query->getAuxFields();
    $this->start = $query->getStartDate($aux_fields);
    $this->end = $query->getEndDate($aux_fields);
    $stats = id(new SprintBuildStats());
    $tasks = $query->getTasks();
    $query->checkNull($this->start, $this->end, $tasks);
    $timezone = $stats->setTimezone($this->viewer);
    $this->before = $stats->buildBefore($this->start, $timezone);
    $dates = $stats->buildDateArray($this->start, $this->end, $timezone);
    $this->timeseries = $stats->buildTimeSeries($this->start, $this->end);


    $xactions = $query->getXactions($tasks);
    $this->events = $query->getEvents($xactions, $tasks);

    $this->xactions = mpull($xactions, null, 'getPHID');
    $this->tasks = mpull($tasks, null, 'getPHID');

    $sprint_xaction = id(new SprintTransaction())
        ->setViewer($this->viewer);
    $sprint_xaction->buildStatArrays($tasks);
    $dates = $sprint_xaction->buildDailyData($this->events, $this->before, $this->start, $this->end, $dates, $this->xactions, $this->project);

    $this->sprint_data = $stats->setSprintData($dates, $this->before);
    $data = $stats->buildDataSet($this->sprint_data);
    $data = $this->transposeArray($data);
    return $data;
  }

  private function transposeArray($array) {
    $transposed_array = array();
    if ($array) {
      foreach ($array as $row_key => $row) {
        if (is_array($row) && !empty($row)) {
          foreach ($row as $column_key => $element) {
            $transposed_array[$column_key][$row_key] = $element;
          }
        } else {
          $transposed_array[0][$row_key] = $row;
        }
      }
    }
    return $transposed_array;
  }

  private function buildC3Chart() {
    $data = $this->buildChartDataSet();
    $totalpoints = $data[0];
    $remainingpoints = $data[1];
    $idealpoints = $data[2];
    $pointstoday = $data[3];
    $timeseries = $this->timeseries;

    require_celerity_resource('d3', 'sprint');
    require_celerity_resource('c3-css', 'sprint');
    require_celerity_resource('c3', 'sprint');

    $id = 'chart';
    Javelin::initBehavior('c3-chart', array(
        'hardpoint' => $id,
        'timeseries' => $timeseries,
        'totalpoints' => $totalpoints,
        'remainingpoints' => $remainingpoints,
        'idealpoints' => $idealpoints,
        'pointstoday' => $pointstoday
    ), 'sprint');

    $chart = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Burndown for ' . $this->project->getName()))
        ->appendChild(phutil_tag('div',
            array(
                'id' => 'chart',
                'style' => 'width: 100%; height:400px'
            ), ''));

    return $chart;
  }

  private function buildC3Pie() {
    $tasks_table = id(new TasksTableView())
        ->setProject($this->project)
        ->setViewer($this->viewer)
        ->setRequest($this->request);
    $tasks_table->setStatusPoints();
    $task_open_status_sum = $tasks_table->getOpenStatusSum();
    $task_closed_status_sum = $tasks_table->getClosedStatusSum();

    require_celerity_resource('d3', 'sprint');
    require_celerity_resource('c3-css', 'sprint');
    require_celerity_resource('c3', 'sprint');

    $id = 'pie';
    Javelin::initBehavior('c3-pie', array(
        'hardpoint' => $id,
        'open' => $task_open_status_sum,
        'resolved' => $task_closed_status_sum,
    ), 'sprint');

    $pie = id(new PHUIObjectBoxView())
        ->setHeaderText(pht('Task Status Report for ' . $this->project->getName()))
        ->appendChild(phutil_tag('div',
            array(
                'id' => 'pie',
                'style' => 'width: 100%; height:200px'
            ), ''));

    return $pie;
  }
}
