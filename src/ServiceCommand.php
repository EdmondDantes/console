<?php
declare(strict_types=1);

namespace IfCastle\Console;

use IfCastle\DI\Dependency;
use IfCastle\DI\DisposableInterface;
use IfCastle\DI\InjectableInterface;
use IfCastle\DI\InjectorTrait;
use IfCastle\ServiceManager\Exceptions\ServiceException;
use IfCastle\ServiceManager\ExecutorInterface;
use IfCastle\TypeDefinitions\DefinitionInterface;
use IfCastle\TypeDefinitions\TypeInternal;
use IfCastle\TypeDefinitions\Value\ValueContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServiceCommand                extends Command
                                    implements InjectableInterface
{
    use InjectorTrait;
    
    #[Dependency]
    protected ExecutorInterface $serviceExecutor;
    
    public function __construct(
        public string $commandName,
        public readonly string $serviceName,
        public readonly string $methodName,
        /**
         * Service command arguments
         */
        protected array $arguments  = [],
        array $aliases              = [],
        string $help                = '',
        string $description         = '',
        bool $isHidden              = false
    )
    {
        parent::__construct($commandName);
        
        /**
         * @see CommandBuildHelper::buildArgumentsAndOptions()
         */
        foreach ($this->arguments as [
                 $isInternal,
                 $type,
                 $definition,
                 $name,
                 $defaultValue,
                 $fromEnv
        ]) {
            
            /* @var $definition DefinitionInterface */
            
            if($isInternal || $fromEnv) {
                continue;
            }
            
            if ($type === 'bool') {
                $this->addOption(
                    $name,
                    null,
                    InputOption::VALUE_NEGATABLE,
                    $description,
                    $defaultValue
                );
            } else {
                $this->addArgument(
                    $name,
                    $definition->isRequired() ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                    $description,
                    $defaultValue
                );
            }
        }
        
        $this->setAliases($aliases);
        $this->setHelp($help);
        $this->setDescription($description);
        $this->setHidden($isHidden);
    }
    
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            
            $result                 = $this->serviceExecutor->executeCommand(
                $this->serviceName, $this->methodName, $this->resolveParameters($input, $output)
            );
            
            $this->outputResult($result, $input, $output);
            
            return self::SUCCESS;
            
        } catch (\Throwable $throwable) {
            $this->outputError($throwable, $input, $output);
            return self::FAILURE;
        }
    }
    
    protected function resolveParameters(InputInterface $input, OutputInterface $output): array
    {
        $parameters                 = [];
        
        foreach ($this->arguments as $key => [
                 $isInternal,
                 $type,
                 $definition,
                 $name,
                 $defaultValue
        ]) {
            
            /* @var $definition DefinitionInterface */
            
            if($isInternal) {
                
                /**
                 * @see CommandBuildHelper::normalizeDefinition()
                 */
                $parameters[$key]   = match ($type) {
                    ProgressDispatcherI::class  => $this->createProgressDispatcher($input, $output),
                    InputInterface::class       => $input,
                    OutputInterface::class      => $output,
                    default                     => throw new ServiceException([
                                    'template'  => 'Unknown internal interface {type} for console command {service}->{command}. '
                                                   .'See CommandBuilder::normalizeDefinition()',
                                    'type'      => $type,
                                    'service'   => $this->serviceName,
                                    'command'   => $this->getName()
                                ])
                };
                
                continue;
            }
            
            if ($type === 'bool') {
                $parameters[$key] = $input->hasOption($name) ? !empty($input->getOption($name)) : $defaultValue;
                continue;
            }
            
            $parameters[$key] = $input->hasArgument($name) ? $definition->decode($input->getArgument($name)) : $defaultValue;
        }
        
        return $parameters;
    }
    
    /**
     * Implements rendering of progressBar according to events.
     *
     *
     */
    protected function createProgressDispatcher(InputInterface $input, OutputInterface $output): ProgressDispatcherI
    {
        $progressBar                = null;
        
        return new ProgressDispatcher(new CallbackEventHandler(static function (EventI $event) use($input, $output, &$progressBar) {
            
            if($event instanceof ProgressItemI) {
                
                if($progressBar === null) {
                    $progressBar    = new ProgressBar($output, $event->getProgressItemTotal());
                    $progressBar->start();
                }
                
                $status         = $event->getProgressItemResult()?->isOk() ? ' [OK]' : ' [ERROR]';
                
                $progressBar->setProgress($event->getProgressItemCurrent());
                $progressBar->setMessage($event->getProgressItemName().$status);
                $progressBar->setMessage((string)memory_get_usage(true), 'memory');
                
            } elseif ($event instanceof ProgressPercentageI) {
                
                if($progressBar === null) {
                    $progressBar    = new ProgressBar($output, 100);
                    $progressBar->start();
                }
                
                $progressBar->setProgress($event->getPercentage());
                $progressBar->setMessage($event->getDescription());
                $progressBar->setMessage((string)memory_get_usage(true), 'memory');
                
            } elseif($event instanceof ProgressI) {
                
                if($progressBar === null) {
                    $progressBar    = new ProgressBar($output);
                    $progressBar->start();
                }
                
                if($event->isProgressCompleted()) {
                    $progressBar->finish();
                } else {
                    $progressBar->advance();
                }
            }
        }));
    }
    
    protected function outputResult(ValueContainerInterface $result, InputInterface $input, OutputInterface $output): void
    {
        if($result->getDefinition() instanceof TypeInternal) {
            $value                  = $result->getValue();
        } else {
            $value                  = $result->containerSerialize();
        }
        
        if($result instanceof DisposableInterface) {
            $result->dispose();
        }
        
        if(is_array($value)) {
            $output->writeln(print_r($value, true));
        } else {
            $output->writeln((string)$value);
        }
    }
    
    protected function outputError(\Throwable $exception, InputInterface $input, OutputInterface $output): void
    {
        $this->getApplication()?->renderThrowable(
            $exception,
            $output instanceof ConsoleOutputInterface ? $output->getErrorOutput()
                : $output
        );
    }
}