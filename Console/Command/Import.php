<?php
namespace EBoost\ImportConsole\Console\Command;

use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\State;
use Magento\Framework\Setup\Option\SelectConfigOption;
use Magento\Store\Model\StoreManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\Setup\Option\TextConfigOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\Import\Adapter as ImportAdapter;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\ImportExport\Model\History as ModelHistory;
use Symfony\Component\Console\Question\Question;

class Import extends Command
{
    const INPUT_KEY_FILE = 'file';
    const INPUT_KEY_ENTITY = 'entity';
    const INPUT_KEY_BEHAVIOR = 'behavior';
    const INPUT_MULTI = 'multi_value_separator';
    const INPUT_FIELD_SEPARATOR = 'field_value_separator';
    protected $objectManager;
    protected $historyModel;
    protected $reportHelper;
    protected $reportProcessor;
    protected $varDirectory;

    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\ImportExport\Helper\Report $reportHelper,
        \Magento\ImportExport\Model\Report\ReportProcessorInterface $reportProcessor,
        \Magento\ImportExport\Model\History $historyModel,
        \Magento\Framework\Filesystem $filesystem
    ){
        $this->objectManager = $objectManager;
        $this->reportHelper = $reportHelper;
        $this->reportProcessor = $reportProcessor;
        $this->varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        parent::__construct();
        $this->historyModel = $historyModel;
    }

    protected function configure()
    {
        $options = [
            /*new TextConfigOption(
                self::INPUT_KEY_ENTITY,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                'eboost-command-import/entity',
                'Entity Type'
            ),*/
            new SelectConfigOption(
                self::INPUT_KEY_ENTITY,
                SelectConfigOption::FRONTEND_WIZARD_SELECT,
                array_keys($this->getAvailableEntityTypeList()),
                'eboost-command-import/entity',
                'Entity Type. Values: ' . implode(', ', array_keys($this->getAvailableEntityTypeList()))
            ),
            new TextConfigOption(
                self::INPUT_KEY_BEHAVIOR,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                'eboost-command-import/behavior',
                'Import Behavior'
            ),
            new TextConfigOption(
                self::INPUT_KEY_FILE,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                'eboost-command-import/file',
                'File To Import'
            ),

            new TextConfigOption(
                self::INPUT_MULTI,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                'eboost-command-import/multi_value_separator',
                'Multi Value Separator'
            ),

            new TextConfigOption(
                self::INPUT_FIELD_SEPARATOR,
                TextConfigOption::FRONTEND_WIZARD_TEXT,
                'eboost-command-import/field_value_separator',
                'Field Value Separator'
            )

        ];
        $this->setName('eboost:import')
            ->setDescription('Import data via command line')
            ->setDefinition($options);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->setAreaCode();
            /**
             * @var \Magento\Framework\Registry
             */
            $registry = $this->objectManager->get('\Magento\Framework\Registry');
            $registry->register('isSecureArea', true);

            $importModel = $this->objectManager->get('\Magento\ImportExport\Model\Import');
            $behaviors = $importModel->getEntityBehaviors();
            $entityTypes = array_keys($behaviors);
            if(!$input->getOption(self::INPUT_KEY_ENTITY) || !in_array($input->getOption(self::INPUT_KEY_ENTITY),$entityTypes)){
                throw new \Exception('Invalid Entity Type. Valid Types: '.implode(', ',$entityTypes));
            }

            $validBehaviors = array_keys($this->objectManager->get($behaviors[$input->getOption(self::INPUT_KEY_ENTITY)]['token'])->toArray());

            if(!$input->getOption(self::INPUT_KEY_BEHAVIOR) || !in_array($input->getOption(self::INPUT_KEY_BEHAVIOR),$validBehaviors)){
                throw new \Exception('Invalid Behavior. Valid Behaviors: '.implode(', ',$validBehaviors));
            }

            $fileName = $input->getOption(self::INPUT_KEY_FILE);
            if (!$fileName) {
                $fileName = $input->getOption(self::INPUT_KEY_ENTITY).'.csv';
            }


            if(!file_exists($importModel->getWorkingDir(). $fileName)){
                throw new \Exception('Import File Is Invalid. Please Upload CSV file To Directory: '.$importModel->getWorkingDir());
            }

            $multiValueSeparator = \Magento\ImportExport\Model\Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR;
            if($input->getOption(self::INPUT_MULTI)) {
                $multiValueSeparator = $input->getOption(self::INPUT_MULTI);
            }

            $fieldValueSeparator = ',';
            if($input->getOption(self::INPUT_FIELD_SEPARATOR)) {
                $fieldValueSeparator = $input->getOption(self::INPUT_FIELD_SEPARATOR);
            }

            $data = array(
                self::INPUT_KEY_ENTITY => $input->getOption(self::INPUT_KEY_ENTITY),
                self::INPUT_KEY_BEHAVIOR => $input->getOption(self::INPUT_KEY_BEHAVIOR),
                $importModel::FIELD_NAME_VALIDATION_STRATEGY => ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_SKIP_ERRORS,
                $importModel::FIELD_NAME_ALLOWED_ERROR_COUNT => 1000,
                $importModel::FIELD_FIELD_SEPARATOR => $fieldValueSeparator,
                $importModel::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR=> $multiValueSeparator,
            );

            $output->writeln('<info>Starting validation...</info>');

            $importModel->setData($data);

            $sourceFile = $importModel->getWorkingDir(). $importModel->getEntity(). '.csv';
            $importedFile = $importModel->getWorkingDir().$fileName;
            if($sourceFile != $importedFile){
                copy($importedFile, $sourceFile);
            }

            $source = ImportAdapter::findAdapterFor(
                $sourceFile,
                $this->objectManager->create('Magento\Framework\Filesystem')
                    ->getDirectoryWrite(DirectoryList::ROOT),
                $data[$importModel::FIELD_FIELD_SEPARATOR]
            );
            $validationResult = $importModel->validateSource($source);

            if (!$importModel->getProcessedRowsCount()) {
                if (!$importModel->getErrorAggregator()->getErrorsCount()) {
                    $output->writeln('<error>This file is empty. Please try another one.</error>');
                } else {
                    foreach ($importModel->getErrorAggregator()->getAllErrors() as $error) {
                        $output->writeln("<error>{$error->getErrorMessage()}</error>");
                    }
                }
            } else {
                $errorAggregator = $importModel->getErrorAggregator();

                $output->writeln("<comment>Checked rows: {$importModel->getProcessedRowsCount()}, checked entities: {$importModel->getProcessedEntitiesCount()}, invalid rows: {$errorAggregator->getInvalidRowsCount()}, total errors: {$errorAggregator->getErrorsCount()}</comment>");

                if (!$validationResult) {
                    $output->writeln('<error>Data validation is failed. Please fix errors and re-upload the file..</error>');
                    $countError = 0;
                    foreach ($errorAggregator->getRowsGroupedByErrorCode() as $errorMessage => $rows) {
                        $output->writeln('<error>'.++$countError.'. '.$errorMessage . ' in rows: ' . implode(', ', $rows).'</error>');
                    }
                    $output->writeln("<comment>Download full report: {$this->createErrorReport($importedFile,$errorAggregator)}</comment>");
                } else {
                    $countError = 0;
                    foreach ($errorAggregator->getRowsGroupedByErrorCode() as $errorMessage => $rows) {
                        $output->writeln('<error>'.++$countError.'. '.$errorMessage . ' in rows: ' . implode(', ', $rows).'</error>');
                    }

                    if ($importModel->isImportAllowed()) {
                        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
                        $questionHelper = $this->getHelper('question');
                        $question = new Question('<question>File is valid! Input Y to continue:</question> ', '');
                        $question->setValidator(function ($value) {
                            if (trim($value) == '') {
                                throw new \Exception('The value cannot be empty');
                            }

                            return $value;
                        });

                        $continueImport = $questionHelper->ask($input, $output, $question);

                        $errorAggregator->clear();
                        if (strtoupper($continueImport) == 'Y') {
                            $output->writeln('<info>File is valid! Starting import process...</info>');
                            $importModel->importSource();
                            $errorAggregator = $importModel->getErrorAggregator();
                            if ($errorAggregator->hasToBeTerminated()) {
                                $output->writeln('<error>Maximum error count has been reached or system error is occurred!</error>');
                                $countError = 0;
                                foreach ($errorAggregator->getRowsGroupedByErrorCode() as $errorMessage => $rows) {
                                    $output->writeln('<error>'.++$countError.'. '.$errorMessage . ' in rows: ' . implode(', ', $rows).'</error>');
                                }
                            } else {
                                $importModel->invalidateIndex();
                                $countError = 0;
                                $noticeHtml = $this->historyModel->getSummary();
                                $output->writeln($noticeHtml);
                                foreach ($errorAggregator->getRowsGroupedByErrorCode() as $errorMessage => $rows) {
                                    $output->writeln('<error>'.++$countError.'. '.$errorMessage . ' in rows: ' . implode(', ', $rows).'</error>');
                                }
                                $output->writeln('<info>Import successfully done</info>');
                            }
                        }
                    } else {
                        $output->writeln('<error>The file is valid, but we can\'t import it for some reason.</error>');
                    }
                }
            }
        }catch (\Exception $e){
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return 0;
    }

    private function setAreaCode()
    {
        $areaCode = 'adminhtml';
        /** @var \Magento\Framework\App\State $appState */
        $appState = $this->objectManager->get('Magento\Framework\App\State');
        $appState->setAreaCode($areaCode);
        /** @var \Magento\Framework\ObjectManager\ConfigLoaderInterface $configLoader */
        $configLoader = $this->objectManager->get('Magento\Framework\ObjectManager\ConfigLoaderInterface');
        $this->objectManager->configure($configLoader->load($areaCode));
    }

    protected function createErrorReport($importedFile,ProcessingErrorAggregatorInterface $errorAggregator)
    {
        $writeOnlyErrorItems = false;
        $fileName = $this->reportProcessor->createReport($importedFile, $errorAggregator, $writeOnlyErrorItems);
        return $this->reportHelper->getReportAbsolutePath($fileName);
    }

    public function getAvailableEntityTypeList()
    {
        if (!isset($this->_availableEntiypeList)) {
            $importModel = $this->objectManager->get('Magento\ImportExport\Model\Import');
            $behaviors = $importModel->getEntityBehaviors();
            $this->_availableEntiypeList =  $behaviors;
        }


        return $this->_availableEntiypeList;
    }
}