<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik\Plugin;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Log;
use Piwik\MetricsFormatter;
use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Site;
use Piwik\View;
use Piwik\View\ViewInterface;
use Piwik\ViewDataTable\Config as VizConfig;
use Piwik\ViewDataTable\Request as ViewDataTableRequest;
use Piwik\ViewDataTable\RequestConfig as VizRequest;
use \Piwik\ViewDataTable\Manager as ViewDataTableManager;

/**
 * This class is used to load (from the API) and customize the output of a given DataTable.
 * The main() method will create an object implementing ViewInterface
 * You can customize the dataTable using the disable* methods.
 *
 * You can also customize the dataTable rendering using row metadata:
 * - 'html_label_prefix': If this metadata is present on a row, it's contents will be prepended
 *                        the label in the HTML output.
 * - 'html_label_suffix': If this metadata is present on a row, it's contents will be appended
 *                        after the label in the HTML output.
 *
 * Example:
 * In the Controller of the plugin VisitorInterest
 * <pre>
 *    function getNumberOfVisitsPerVisitDuration( $fetch = false)
 *  {
 *        $view = ViewDataTable::factory( 'cloud' );
 *        $view->init( $this->pluginName,  __FUNCTION__, 'VisitorInterest.getNumberOfVisitsPerVisitDuration' );
 *        $view->setColumnsToDisplay( array('label','nb_visits') );
 *        $view->disableSort();
 *        $view->disableExcludeLowPopulation();
 *        $view->disableOffsetInformation();
 *
 *        return $this->renderView($view, $fetch);
 *    }
 * </pre>
 *
 * @see factory() for all the available output (cloud tags, html table, pie chart, vertical bar chart)
 * @package Piwik
 * @subpackage ViewDataTable
 *
 * @api
 */
abstract class ViewDataTable implements ViewInterface
{
    const ID = '';
    const CONFIGURE_FOOTER_ICONS_EVENT = 'Visualization.configureFooterIcons';

    /**
     * DataTable loaded from the API for this ViewDataTable.
     *
     * @var DataTable
     */
    protected $dataTable = null;

    /**
     * @var \Piwik\ViewDataTable\Config
     */
    public $config;

    /**
     * @var \Piwik\ViewDataTable\RequestConfig
     */
    public $requestConfig;

    /**
     * @var ViewDataTableRequest
     */
    protected $request;

    /**
     * Default constructor.
     */
    public function __construct($controllerAction, $apiMethodToRequestDataTable)
    {
        list($controllerName, $controllerAction) = explode('.', $controllerAction);

        $this->requestConfig = static::getDefaultRequestConfig();
        $this->config        = static::getDefaultConfig();
        $this->config->subtable_controller_action = $controllerAction;
        $this->config->setController($controllerName, $controllerAction);

        $this->request = new ViewDataTableRequest($this->requestConfig);

        $this->requestConfig->idSubtable = Common::getRequestVar('idSubtable', false, 'int');
        $this->config->self_url          = Request::getBaseReportUrl($controllerName, $controllerAction);

        $this->requestConfig->apiMethodToRequestDataTable = $apiMethodToRequestDataTable;

        /**
         * This event is triggered to gather the report display properties for each available report. If you define
         * your own report, you want to subscribe to this event to define how your report shall be displayed in the
         * Piwik UI.
         *
         * public function configureViewDataTable(ViewDataTable $view)
         * {
         *     switch ($view->requestConfig->apiMethodToRequestDataTable) {
         *         case 'VisitTime.getVisitInformationPerServerTime':
         *             $view->config->enable_sort = true;
         *             $view->requestConfig->filter_limit = 10;
         *             break;
         *     }
         * }
         */
        Piwik::postEvent('ViewDataTable.configure', array($this));

        $this->config->show_footer_icons = (false == $this->requestConfig->idSubtable);

        // the exclude low population threshold value is sometimes obtained by requesting data.
        // to avoid issuing unecessary requests when display properties are determined by metadata,
        // we allow it to be a closure.
        if (isset($this->requestConfig->filter_excludelowpop_value)
            && $this->requestConfig->filter_excludelowpop_value instanceof \Closure
        ) {
            $function = $this->requestConfig->filter_excludelowpop_value;
            $this->requestConfig->filter_excludelowpop_value = $function();
        }

        $this->overrideViewPropertiesWithQueryParams();
    }

    public static function getDefaultConfig()
    {
        return new VizConfig();
    }

    public static function getDefaultRequestConfig()
    {
        return new VizRequest();
    }

    protected function loadDataTableFromAPI()
    {
        if (!is_null($this->dataTable)) {
            // data table is already there
            // this happens when setDataTable has been used
            return $this->dataTable;
        }

        $this->dataTable = $this->request->loadDataTableFromAPI();

        return $this->dataTable;
    }

    /**
     * Returns the viewDataTable ID for this DataTable visualization. Derived classes
     * should declare a const ID field with the viewDataTable ID.
     *
     * @throws \Exception
     * @return string
     */
    public static function getViewDataTableId()
    {
        $id = static::ID;

        if (empty($id)) {
            $message = sprintf('ViewDataTable %s does not define an ID. Set the ID constant to fix this issue', get_called_class());
            throw new \Exception($message);
        }

       return $id;
    }

    public function isViewDataTableId($viewDataTableId)
    {
        $myIds = ViewDataTableManager::getIdsWithInheritance(get_called_class());

        return in_array($viewDataTableId, $myIds);
    }

    /**
     * Returns the DataTable loaded from the API
     *
     * @return DataTable
     * @throws \Exception if not yet defined
     */
    public function getDataTable()
    {
        if (is_null($this->dataTable)) {
            throw new \Exception("The DataTable object has not yet been created");
        }

        return $this->dataTable;
    }

    /**
     * To prevent calling an API multiple times, the DataTable can be set directly.
     * It won't be loaded again from the API in this case
     *
     * @param $dataTable
     * @return void $dataTable DataTable
     */
    public function setDataTable($dataTable)
    {
        $this->dataTable = $dataTable;
    }

    /**
     * Checks that the API returned a normal DataTable (as opposed to DataTable\Map)
     * @throws \Exception
     * @return void
     */
    protected function checkStandardDataTable()
    {
        Piwik::checkObjectTypeIs($this->dataTable, array('\Piwik\DataTable'));
    }

    /**
     * Convenience function. Calls main() & renders the view that gets built.
     *
     * @return string The result of rendering.
     */
    public function render()
    {
        $view = $this->buildView();
        return $view->render();
    }

    abstract protected function buildView();

    protected function getDefaultFooterIconsToShow()
    {
        $result = array();

        // add normal view icons (eg, normal table, all columns, goals)
        $normalViewIcons = array(
            'class'   => 'tableAllColumnsSwitch',
            'buttons' => array(),
        );

        if ($this->config->show_table) {
            $normalViewIcons['buttons'][] = array(
                'id'    => 'table',
                'title' => Piwik::translate('General_DisplaySimpleTable'),
                'icon'  => 'plugins/Zeitgeist/images/table.png',
            );
        }

        if ($this->config->show_table_all_columns) {
            $normalViewIcons['buttons'][] = array(
                'id'    => 'tableAllColumns',
                'title' => Piwik::translate('General_DisplayTableWithMoreMetrics'),
                'icon'  => 'plugins/Zeitgeist/images/table_more.png'
            );
        }

        if ($this->config->show_goals) {
            if (Common::getRequestVar('idGoal', false) == 'ecommerceOrder') {
                $icon = 'plugins/Zeitgeist/images/ecommerceOrder.gif';
            } else {
                $icon = 'plugins/Zeitgeist/images/goal.png';
            }

            $normalViewIcons['buttons'][] = array(
                'id'    => 'tableGoals',
                'title' => Piwik::translate('General_DisplayTableWithGoalMetrics'),
                'icon'  => $icon
            );
        }

        if ($this->config->show_ecommerce) {
            $normalViewIcons['buttons'][] = array(
                'id'    => 'ecommerceOrder',
                'title' => Piwik::translate('General_EcommerceOrders'),
                'icon'  => 'plugins/Zeitgeist/images/ecommerceOrder.gif',
                'text'  => Piwik::translate('General_EcommerceOrders')
            );

            $normalViewIcons['buttons'][] = array(
                'id'    => 'ecommerceAbandonedCart',
                'title' => Piwik::translate('General_AbandonedCarts'),
                'icon'  => 'plugins/Zeitgeist/images/ecommerceAbandonedCart.gif',
                'text'  => Piwik::translate('General_AbandonedCarts')
            );
        }

        if (!empty($normalViewIcons['buttons'])) {
            $result[] = $normalViewIcons;
        }

        // add graph views
        $graphViewIcons = array(
            'class'   => 'tableGraphViews tableGraphCollapsed',
            'buttons' => array(),
        );

        if ($this->config->show_all_views_icons) {
            if ($this->config->show_bar_chart) {
                $graphViewIcons['buttons'][] = array(
                    'id'    => 'graphVerticalBar',
                    'title' => Piwik::translate('General_VBarGraph'),
                    'icon'  => 'plugins/Zeitgeist/images/chart_bar.png'
                );
            }

            if ($this->config->show_pie_chart) {
                $graphViewIcons['buttons'][] = array(
                    'id'    => 'graphPie',
                    'title' => Piwik::translate('General_Piechart'),
                    'icon'  => 'plugins/Zeitgeist/images/chart_pie.png'
                );
            }

            if ($this->config->show_tag_cloud) {
                $graphViewIcons['buttons'][] = array(
                    'id'    => 'cloud',
                    'title' => Piwik::translate('General_TagCloud'),
                    'icon'  => 'plugins/Zeitgeist/images/tagcloud.png'
                );
            }

            if ($this->config->show_non_core_visualizations) {
                $nonCoreVisualizations    = ViewDataTableManager::getNonCoreViewDataTables();
                $nonCoreVisualizationInfo = ViewDataTableManager::getViewDataTableInfoFor($nonCoreVisualizations);

                foreach ($nonCoreVisualizationInfo as $format => $info) {
                    $graphViewIcons['buttons'][] = array(
                        'id'    => $format,
                        'title' => Piwik::translate($info['title']),
                        'icon'  => $info['table_icon']
                    );
                }
            }
        }

        if (!empty($graphViewIcons['buttons'])) {
            $result[] = $graphViewIcons;
        }

        /**
         * This event is called when determining the default set of footer icons to display
         * below a report.
         *
         * Plugins can use this event to modify the default set of footer icons. You can
         * add new icons or remove existing ones.
         *
         * $result must have the following format:
         *
         * ```
         * array(
         *     array( // footer icon group 1
         *         'class' => 'footerIconGroup1CssClass',
         *         'buttons' => array(
         *             'id' => 'myid',
         *             'title' => 'My Tooltip',
         *             'icon' => 'path/to/my/icon.png'
         *         )
         *     ),
         *     array( // footer icon group 2
         *         'class' => 'footerIconGroup2CssClass',
         *         'buttons' => array(...)
         *     ),
         *     ...
         * )
         * ```
         */
        Piwik::postEvent(self::CONFIGURE_FOOTER_ICONS_EVENT, array(&$result, $viewDataTable = $this));

        return $result;
    }

    protected function getDefaultDataTableCssClass()
    {
        return 'dataTableViz' . Piwik::getUnnamespacedClassName(get_class($this));
    }

    /**
     * Returns the list of view properties that can be overriden by query parameters.
     *
     * @return array
     */
    protected function getOverridableProperties()
    {
        return array_merge($this->config->overridableProperties, $this->requestConfig->overridableProperties);
    }

    private function overrideViewPropertiesWithQueryParams()
    {
        $properties = $this->getOverridableProperties();

        foreach ($properties as $name) {
            if (property_exists($this->requestConfig, $name)) {
                $this->requestConfig->name = $this->getPropertyFromQueryParam($name, $this->requestConfig->$name);
            } elseif (property_exists($this->config, $name)) {
                $this->config->name  = $this->getPropertyFromQueryParam($name, $this->config->$name);
            }
        }

        // handle special 'columns' query parameter
        $columns = Common::getRequestVar('columns', false);

        if (false !== $columns) {
            $this->config->columns_to_display = Piwik::getArrayFromApiParameter($columns);
            array_unshift($this->config->columns_to_display, 'label');
        }
    }

    protected function getPropertyFromQueryParam($name, $defaultValue)
    {
        $type = is_numeric($defaultValue) ? 'int' : null;
        return Common::getRequestVar($name, $defaultValue, $type);
    }

    public function isRequestingSingleDataTable()
    {
        $requestArray = $this->request->getRequestArray() + $_GET + $_POST;
        $date   = Common::getRequestVar('date', null, 'string', $requestArray);
        $period = Common::getRequestVar('period', null, 'string', $requestArray);
        $idSite = Common::getRequestVar('idSite', null, 'string', $requestArray);

        if (Period::isMultiplePeriod($date, $period)
            || strpos($idSite, ',') !== false
            || $idSite == 'all'
        ) {
            return false;
        }

        return true;
    }
}