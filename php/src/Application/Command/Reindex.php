<?php

namespace PhpIntegrator\Application\Command;

use ArrayAccess;
use UnexpectedValueException;

use GetOptionKit\OptionCollection;

use PhpIntegrator\Scanner;
use PhpIntegrator\DocParser;
use PhpIntegrator\FileIndexer;
use PhpIntegrator\TypeAnalyzer;
use PhpIntegrator\ProjectIndexer;
use PhpIntegrator\BuiltinIndexer;
use PhpIntegrator\IndexStorageItemEnum;

use PhpIntegrator\Application\Command as BaseCommand;

use PhpParser\ParserFactory;

/**
 * Command that reindexes a file or folder.
 */
class Reindex extends BaseCommand
{
    /**
     * @var ProjectIndexer
     */
    protected $projectIndexer;

    /**
     * @var FileIndexer
     */
    protected $fileIndexer;

    /**
     * @var BuiltinIndexer
     */
    protected $builtinIndexer;

    /**
     * @var Scanner
     */
    protected $scanner;

    /**
     * @var array
     */
    protected $fileModifiedMap;

    /**
     * @var DocParser
     */
    protected $docParser;

    /**
     * @var TypeAnalyzer
     */
    protected $typeAnalyzer;

    /**
     * @var ParserFactory
     */
    protected $parserFactory;

    /**
     * @inheritDoc
     */
    protected function attachOptions(OptionCollection $optionCollection)
    {
        $optionCollection->add('source:', 'The file or directory to index.')->isa('string');
        $optionCollection->add('stdin?', 'If set, file contents will not be read from disk but the contents from STDIN will be used instead.');
        $optionCollection->add('v|verbose?', 'If set, verbose output will be displayed.');
        $optionCollection->add('s|stream-progress?', 'If set, progress will be streamed. Incompatible with verbose mode.');
    }

    /**
     * @inheritDoc
     */
    protected function process(ArrayAccess $arguments)
    {
        if (!isset($arguments['source'])) {
            throw new UnexpectedValueException('The file or directory to index is required for this command.');
        }

        return $this->reindex(
            $arguments['source']->value,
            isset($arguments['stdin']),
            isset($arguments['verbose']),
            isset($arguments['stream-progress'])
        );
    }

    /**
     * @param string $path
     * @param bool   $useStdin
     * @param bool   $showOutput
     * @param bool   $doStreamProgress
     */
    public function reindex($path, $useStdin, $showOutput, $doStreamProgress)
    {
        $hasIndexedBuiltin = $this->indexDatabase->getConnection()->createQueryBuilder()
            ->select('id', 'value')
            ->from(IndexStorageItemEnum::SETTINGS)
            ->where('name = ?')
            ->setParameter(0, 'has_indexed_builtin')
            ->execute()
            ->fetch();

        if (!$hasIndexedBuiltin || !$hasIndexedBuiltin['value']) {
            $builtinIndexer = new BuiltinIndexer($this->indexDatabase, $showOutput);

            $builtinIndexer->index();

            if ($hasIndexedBuiltin) {
                $this->indexDatabase->update(IndexStorageItemEnum::SETTINGS, $hasIndexedBuiltin['id'], [
                    'value' => 1
                ]);
            } else {
                $this->indexDatabase->insert(IndexStorageItemEnum::SETTINGS, [
                    'name'  => 'has_indexed_builtin',
                    'value' => 1
                ]);
            }
        }

        if (is_dir($path)) {
            $this->getProjectIndexer()
                ->setStreamProgress($doStreamProgress)
                ->setShowOutput($showOutput)
                ->index($path);

            return $this->outputJson(true, []);
        } elseif (is_file($path) || $useStdin) {
            $code = null;

            if ($useStdin) {
                // NOTE: This call is blocking if there is no input!
                $code = file_get_contents('php://stdin');
            }

            $isInMemoryDatabase = ($this->indexDatabase->getDatabasePath() === ':memory:');

            if (!$isInMemoryDatabase) {
                // All other commands don't abide by these locks, so they can just happily continue using the database (as
                // they are only reading, that poses no problem). However, writing in a transaction will cause the database
                // to become locked, which poses a problem if two simultaneous reindexing processes are spawned. If that
                // happens, just block until the database becomes available again. If we don't, we will receive an
                // exception from the driver.
                $f = fopen($this->indexDatabase->getDatabasePath(), 'rw');
                flock($f, LOCK_EX);
            }

            try {
                $this->getFileIndexer()
                    ->setShowOutput($showOutput)
                    ->index($path, $code ?: null);
            } catch (Indexer\IndexingFailedException $e) {
                return $this->outputJson(false, []);
            }

            if (!$isInMemoryDatabase) {
                flock($f, LOCK_UN);
            }

            return $this->outputJson(true, []);
        }

        throw new UnexpectedValueException('The specified file or directory "' . $path . '" does not exist!');
    }

    /**
     * @return ProjectIndexer
     */
    protected function getProjectIndexer()
    {
        if (!$this->projectIndexer) {
            $this->projectIndexer = new ProjectIndexer(
                $this->indexDatabase,
                $this->getBuiltinIndexer(),
                $this->getFileIndexer(),
                $this->getScanner()
            );
        }

        return $this->projectIndexer;
    }

    /**
     * @return FileIndexer
     */
    protected function getFileIndexer()
    {
        if (!$this->fileIndexer) {
            $this->fileIndexer = new FileIndexer(
                $this->indexDatabase,
                $this->getTypeAnalyzer(),
                $this->getDocParser(),
                $this->getParserFactory()
            );
        }

        return $this->fileIndexer;
    }

    /**
     * @return BuiltinIndexer
     */
    protected function getBuiltinIndexer()
    {
        if (!$this->builtinIndexer) {
            $this->builtinIndexer = new BuiltinIndexer($this->indexDatabase);
        }

        return $this->builtinIndexer;
    }

    /**
     * @return Scanner
     */
    protected function getScanner()
    {
        if (!$this->scanner) {
            $this->scanner = new Scanner($this->getFileModifiedMap());
        }

        return $this->scanner;
    }

    /**
     * @return array
     */
    protected function getFileModifiedMap()
    {
        if (!$this->fileModifiedMap) {
            $this->fileModifiedMap = $this->indexDatabase->getFileModifiedMap();
        }

        return $this->fileModifiedMap;
    }

    /**
     * @return TypeAnalyzer
     */
    protected function getTypeAnalyzer()
    {
        if (!$this->typeAnalyzer) {
            $this->typeAnalyzer = new TypeAnalyzer();
        }

        return $this->typeAnalyzer;
    }

    /**
     * @return DocParser
     */
    protected function getDocParser()
    {
        if (!$this->docParser) {
            $this->docParser = new DocParser();
        }

        return $this->docParser;
    }

    /**
     * @return DocParser
     */
    protected function getParserFactory()
    {
        if (!$this->parserFactory) {
            $this->parserFactory = new ParserFactory();
        }

        return $this->parserFactory;
    }
}
