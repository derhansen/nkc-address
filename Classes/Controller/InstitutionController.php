<?php

namespace Nordkirche\NkcAddress\Controller;

use Nordkirche\Ndk\Domain\Model\Institution\Institution;
use Nordkirche\Ndk\Domain\Model\Institution\OpeningHours;
use Nordkirche\Ndk\Domain\Model\Person\PersonFunction;
use Nordkirche\Ndk\Domain\Repository\FunctionTypeRepository;
use Nordkirche\Ndk\Domain\Repository\InstitutionRepository;
use Nordkirche\Ndk\Domain\Repository\PersonRepository;
use Nordkirche\NkcAddress\Domain\Dto\SearchRequest;
use Nordkirche\Ndk\Domain\Model\Institution\Team;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

class InstitutionController extends \Nordkirche\NkcBase\Controller\BaseController
{

    /**
     * @var \Nordkirche\Ndk\Domain\Repository\InstitutionRepository
     */
    protected $institutionRepository;

    /**
     * @var \Nordkirche\Ndk\Domain\Repository\PersonRepository
     */
    protected $personRepository;

    /**
     * @var \Nordkirche\Ndk\Domain\Repository\FunctionTypeRepository
     */
    protected $functionTypeRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Domain\Repository\CategoryRepository
     * @TYPO3\CMS\Extbase\Annotation\Inject
     */
    protected $categoryRepository;

    /**
     * @var StandaloneView
     */
    protected $standaloneView;

    public function initializeAction()
    {
        parent::initializeAction();
        $this->institutionRepository = $this->api->factory(InstitutionRepository::class);
        $this->personRepository = $this->api->factory(PersonRepository::class);
        $this->functionTypeRepository= $this->api->factory(FunctionTypeRepository::class);

        if ($this->arguments->hasArgument('searchRequest')) {
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('search');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('city');
            $this->arguments->getArgument('searchRequest')->getPropertyMappingConfiguration()->allowProperties('category');
        }
    }

    /**
     * List action
     *
     * @param int $currentPage
     * @param \Nordkirche\NkcAddress\Domain\Dto\SearchRequest $searchRequest
     */
    public function listAction($currentPage = 1, $searchRequest = null)
    {
        $query = new \Nordkirche\Ndk\Domain\Query\InstitutionQuery();

        // Set Included objects
        $query->setInclude([Institution::RELATION_ADDRESS, Institution::RELATION_INSTITUTION_TYPE]);

        // Set pagination parameters
        $this->setPagination($query, $currentPage);

        // Filter institutions
        $this->setInstitutionFilter($query, $this->settings['flexform']['institutionCollection'], $this->settings['flexform']['selectOption']);

        // Filter by type
        $this->setInstitutionTypeFilter($query, $this->settings['flexform']['institutionType']);

        // Filter by geoposition
        $this->setGeoFilter(
            $query,
            $this->settings['flexform']['geosearch'],
            $this->settings['flexform']['latitude'],
            $this->settings['flexform']['longitude'],
            $this->settings['flexform']['radius']
        );

        if ($searchRequest instanceof SearchRequest) {
            $this->setUserFilters($query, $searchRequest);
        } else {
            $searchRequest = new SearchRequest();
        }

        // Sorting
        if ($this->settings['flexform']['sortOption']) {
            $query->setSort($this->settings['flexform']['sortOption']);
        }

        // Get institutions
        $institutions = $this->institutionRepository->get($query);

        // Get current cObj
        $cObj = $this->configurationManager->getContentObject();

        $this->view->assignMultiple([
            'query' => $query,
            'institutions' => $institutions,
            'content' => $cObj->data,
            'filter' => $this->getFilterValues(),
            'searchPid' => $GLOBALS['TSFE']->id,
            'searchRequest' => $searchRequest

        ]);
    }

    /**
     * @param \Nordkirche\NkcAddress\Domain\Dto\SearchRequest $searchRequest
     */
    public function searchFormAction($searchRequest = null)
    {
        if (!($searchRequest instanceof SearchRequest)) {
            $searchRequest = GeneralUtility::makeInstance(SearchRequest::class);
        }

        $this->view->assignMultiple([
            'searchPid' => $this->settings['flexform']['pidList'] ? $this->settings['flexform']['pidList'] : $GLOBALS['TSFE']->id,
            'filter' => $this->getFilterValues(),
            'searchRequest' => $searchRequest
        ]);
    }

    /**
     * @param \Nordkirche\NkcAddress\Domain\Dto\SearchRequest $searchRequest
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function searchAction($searchRequest = null)
    {
        if (!($searchRequest instanceof SearchRequest)) {
            $searchRequest = GeneralUtility::makeInstance(SearchRequest::class);
        }

        $this->uriBuilder->setRequest($this->request);
        $uri = $this->uriBuilder->uriFor('list', ['searchRequest' => $searchRequest->toArray()]);
        $this->redirectToURI($uri);
    }

    /**
     * @param int $uid
     * @throws ImmediateResponseException
     */
    public function showAction($uid = null)
    {
        $includes = [
            Institution::RELATION_ADDRESS,
            Institution::RELATION_INSTITUTION_TYPE,
            Institution::RELATION_MAP_CHILDREN,
            Institution::RELATION_PARENT_INSTITUTIONS,
            Institution::RELATION_TEAMS => [
                Team::RELATION_FUNCTIONS => [
                    PersonFunction::RELATION_PERSON
                ],
                Team::RELATION_FUNCTION_TYPE,
            ]
        ];

        if ($this->settings['flexform']['singleInstitution']) {
            // Institution is selected in flexform
            try {
                /** @var Institution $institution */
                $institution = $this->napiService->resolveUrl($this->settings['flexform']['singleInstitution'], $includes);
            } catch (\Exception $e) {
                $institution = false;
            }
        } elseif ((int)$uid) {
            // Find by uid
            try {
                /** @var Institution $institution */
                $institution = $this->institutionRepository->getById($uid, $includes);
            } catch (\Exception $e) {
                $institution = false;
            }
        } else {
            $institution = false;
        }

        if ($institution) {
            // Get sub objects
            $openingHours = [];

            $this->settings['mapInfo']['recordUid'] = $institution->getId();

            // Get children
            $childInstitutions = $this->getChildInstitutions($institution);

            // Get map markers
            $mapMarkers = $this->getMapMarkers($institution);

            // Parse opening hours table
            if ($this->settings['flexform']['openingHours']) {
                $openingHours = $this->prepareOpeningHours($institution);
            }

            // Get current cObj
            $cObj = $this->configurationManager->getContentObject();

            $this->view->assignMultiple([
                'institution' => $institution,
                'childInstitutions' => $childInstitutions,
                'mapMarkers' => $mapMarkers,
                'openingHours' => $openingHours,
                'content' => $cObj->data
            ]);

        } else {
            // Page not found
            $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                $GLOBALS['TYPO3_REQUEST'],
                'Einrichtung konnte nicht gefunden werden',
                ['code' => PageAccessFailureReasons::PAGE_NOT_FOUND]
            );
            throw new ImmediateResponseException($response);
        }

    }

    /**
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function redirectAction()
    {
        if ($nkci = (int)(GeneralUtility::_GP('nkci'))) {
            $this->uriBuilder->reset()->setTargetPageUid($this->settings['flexform']['pidSingle']);
            $uri = $this->uriBuilder->uriFor('show', ['uid' => $nkci]);
            $this->redirectToURI($uri);
        } else {
            $this->redirectToURI('/');
        }
    }

    /**
     * Create marker array
     *
     * @param \Nordkirche\Ndk\Domain\Model\Institution\Institution $institution
     * @return array
     */
    private function getMapMarkers($institution)
    {
        $mapMarkers = [];

        if ($institution->getMapVisibility() == true) {
            $this->createMarker($mapMarkers, $institution);
            if ($institution->getMapChildren()) {
                /** @var \Nordkirche\Ndk\Domain\Model\Institution\Institution $childInstitution */
                foreach ($institution->getMapChildren() as $childInstitution) {
                    $this->createMarker($mapMarkers, $childInstitution);
                }
            }
        }

        return $mapMarkers;
    }

    /**
     * @param \Nordkirche\Ndk\Domain\Model\Institution\Institution $institution
     * @return string
     */
    public function renderMapInfo($institution)
    {
        if ($this->standaloneView == false) {
            // Init standalone view

            $config = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);

            $absTemplatePaths = [];
            foreach ($config['view']['templateRootPaths'] as $path) {
                $absTemplatePaths[] = GeneralUtility::getFileAbsFileName($path);
            }

            if (count($absTemplatePaths) == 0) {
                $absTemplatePaths[] =  GeneralUtility::getFileAbsFileName('EXT:nkc_address/Resources/Private/Templates/');
            }

            $absLayoutPaths = [];
            foreach ($config['view']['layoutRootPaths'] as $path) {
                $absLayoutPaths[] = GeneralUtility::getFileAbsFileName($path);
            }

            if (count($absLayoutPaths) == 0) {
                $absLayoutPaths[] = GeneralUtility::getFileAbsFileName('EXT:nkc_address/Resources/Private/Layouts/');
            }

            $absPartialPaths = [];
            foreach ($config['view']['partialRootPaths'] as $path) {
                $absPartialPaths[] = GeneralUtility::getFileAbsFileName($path);
            }

            if (count($absPartialPaths) == 0) {
                $absPartialPaths[] = GeneralUtility::getFileAbsFileName('EXT:nkc_address/Resources/Private/Partials/');
            }

            $this->standaloneView = GeneralUtility::makeInstance(StandaloneView::class);

            $this->standaloneView->setLayoutRootPaths(
                $absLayoutPaths
            );
            $this->standaloneView->setPartialRootPaths(
                $absPartialPaths
            );
            $this->standaloneView->setTemplateRootPaths(
                $absTemplatePaths
            );

            $this->standaloneView->setTemplate('Institution/MapInfo');
        }

        $this->standaloneView->assignMultiple(['institution' => $institution,
            'settings' => $this->settings]);

        return $this->standaloneView->render();
    }

    /**
     * Add a marker, when geo-coordinates are available
     *
     * @param array $mapMarkers
     * @param \Nordkirche\Ndk\Domain\Model\Institution\Institution $institution
     */
    public function createMarker(&$mapMarkers, $institution)
    {

        // Get type of institution
        if ($institution->getInstitutionType() instanceof \Nordkirche\Ndk\Domain\Model\Institution\InstitutionType) {
            $typeId = $institution->getInstitutionType()->getId();
        } else {
            $typeId = 0;
        }

        /** @var \Nordkirche\Ndk\Domain\Model\Address $address */
        $address = $institution->getAddress();
        if ($address instanceof \Nordkirche\Ndk\Domain\Model\Address) {
            // Check geo coordinates
            if ($address->getLatitude() && $address->getLongitude()) {
                $marker = [
                    'title' => $institution->getName(),
                    'lat' 	=> $address->getLatitude(),
                    'lon' 	=> $address->getLongitude(),
                    'info' 	=> $this->renderMapInfo($institution),
                    'type'  => 'institution-' . $typeId,
                    'icon' 	=> $this->getIcon($typeId)
                ];
                $mapMarkers[] = $marker;
            }
        }
    }

    /**
     * @param $id
     * @return string
     */
    public function mapInstitutionTypeToKeyword($id)
    {
        return $this->settings['mapping']['institutionIcon'][$id] ? $this->settings['mapping']['institutionIcon'][$id] : 'default';
    }

    /**
     * @param int $typeId
     * @return string
     */
    private function getIcon($typeId)
    {
        $filename = sprintf($this->settings['institutionIconName'], $this->mapInstitutionTypeToKeyword($typeId));
        $checkFilename = substr($filename, 0, 1) == '/' ? substr($filename, 1) : $filename;
        if (!is_file(GeneralUtility::getFileAbsFileName($checkFilename))) {
            $filename = sprintf($this->settings['institutionIconName'], 'default');
        }

        return $filename;
    }

    /**
     * Get all child institutions, clustered by type
     *
     * @param $institution
     * @return array
     */
    private function getChildInstitutions($institution)
    {
        $groupedChildInstitutions = [];

        $query = new \Nordkirche\Ndk\Domain\Query\InstitutionQuery();

        $query->setParentInstitutions([$institution->getId()]);

        $query->setInclude([ Institution::RELATION_ADDRESS, Institution::RELATION_INSTITUTION_TYPE ]);

        $query->setPageSize(99);

        $childInstitutions = $this->institutionRepository->get($query);

        if ($childInstitutions) {
            foreach ($childInstitutions as $childInstitution) {
                if ($childInstitution->getInstitutionType()) {
                    $groupedChildInstitutions[$childInstitution->getInstitutionType()->getName()][] = $childInstitution;
                } else {
                    $groupedChildInstitutions['Sonstige'][] = $childInstitution;
                }
            }
        }

        ksort($groupedChildInstitutions);

        return $groupedChildInstitutions;
    }

    /**
     * @param \Nordkirche\Ndk\Domain\Query\InstitutionQuery $query
     * @param \Nordkirche\NkcAddress\Domain\Dto\SearchRequest $searchRequest
     */
    private function setUserFilters($query, $searchRequest)
    {
        if (($searchRequest->getSearch() != '') && (strlen($searchRequest->getSearch()) > 2)) {
            $query->setQuery($searchRequest->getSearch());
        }

        if (($searchRequest->getCity() != '') && (strlen($searchRequest->getCity()) > 2)) {
            if (($searchRequest->getCity() != '') && (strlen($searchRequest->getCity()) > 2)) {
                if (is_numeric($searchRequest->getCity())) {
                    $query->setZipCodes([$searchRequest->getCity()]);
                } else {
                    $query->setCities(GeneralUtility::trimExplode(',', $searchRequest->getCity()));
                }
            }
        }

        if ($searchRequest->getCategory() > 0) {
            if ($categories = $query->getCategories()) {
                if (in_array($searchRequest->getCategory(), $categories)) {
                    $query->setCategories([$searchRequest->getCategory()]);
                }
            } else {
                $query->setCategories([$searchRequest->getCategory()]);
            }
        }
    }

    /**
     * @return array
     */
    private function getFilterValues()
    {
        $filter = [];

        if ($this->settings['filter']['cityCollection']) {
            $cities = GeneralUtility::trimExplode(',', $this->settings['filter']['cityCollection']);

            $index = 0;
            if (count($cities)) {
                foreach ($cities as $city) {
                    $filter['cities'][$index] = [];
                    $filter['cities'][$index]['name'] = $city;
                    $index++;
                }
            }
        }

        if ($this->settings['filter']['categoryCollection']) {
            $categories = GeneralUtility::trimExplode(',', $this->settings['filter']['categoryCollection']);
            foreach ($categories as $categoryUid) {
                $category = $this->categoryRepository->findByUid($categoryUid);
                if ($category instanceof \TYPO3\CMS\Extbase\Domain\Model\Category) {
                    $filter['categories'][] = [
                        'uid'   => $category->getUid(),
                        'label' => $category->getTitle()
                    ];
                }
            }
        }

        return $filter;
    }

    /**
     * Prepare opening hours for output
     *
     * @param Institution $institution
     * @return array
     */
    /**
     * Prepare opening hours for output
     *
     * @param Institution $institution
     * @return array
     */
    private function prepareOpeningHours($institution)
    {
        $openingHoursTable = [];
        /** @var OpeningHours $openingHours */
        foreach($institution->getOpeningHours() as $openingHours) {
            if (!isset($openingHoursTable[$openingHours->getDayOfWeek()])) {
                $openingHoursTable[$openingHours->getDayOfWeek()] = [
                    'day'   => $openingHours->getDayOfWeek(),
                    'name'  => '',
                    'items' => []
                ];
            }

            if ($openingHours->getName()) {
                $openingHoursTable[$openingHours->getDayOfWeek()]['name'] = $openingHours->getName();
            }

            $openingHoursTable[$openingHours->getDayOfWeek()]['items'][] = $openingHours;
        }

        $max = 0;
        foreach($openingHoursTable as $openingHours) {
            $size = sizeof($openingHours['items']);
            if ($size > $max) $max = $size;
        }

        $size = $max;

        foreach($openingHoursTable as $index => $openingHours) {
            if (sizeof($openingHours['items']) < $size) {
                for($i = sizeof($openingHours['items']); $i< $max; $i++) {
                    $openingHoursTable[$index]['items'][] = false;
                }
            }
        }

        return $openingHoursTable;
    }

    /**
     * Napi returns incomplete date for opening hours.
     * NDK cannot convert to DateTime object and returns it as string
     *
     * @param string $date
     * @return string
     */
    private function convertDate($date)
    {
        return implode('.', array_reverse(explode('-', $date)));
    }

}
