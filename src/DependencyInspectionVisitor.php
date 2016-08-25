<?php

declare(strict_types=1);

namespace Mihaeu\PhpDependencies;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall as MethodCallNode;
use PhpParser\Node\Expr\StaticCall as StaticCallNode;
use PhpParser\Node\Name as NameNode;
use PhpParser\Node\Name\FullyQualified as FullyQualifiedNameNode;
use PhpParser\Node\Expr\New_ as NewNode;
use PhpParser\Node\Stmt\Class_ as ClassNode;
use PhpParser\Node\Stmt\ClassLike as ClassLikeNode;
use PhpParser\Node\Stmt\ClassMethod as ClassMethodNode;
use PhpParser\Node\Stmt\Interface_ as InterfaceNode;
use PhpParser\Node\Stmt\Use_ as UseNode;
use PhpParser\NodeVisitorAbstract;

class DependencyInspectionVisitor extends NodeVisitorAbstract
{
    /** @var DependencyPairCollection */
    private $dependencies;

    /** @var DependencyPairCollection */
    private $tempDependencies;

    /** @var Clazz */
    private $currentClass = null;

    /** @var Clazz */
    private $temporaryClass;

    /** @var DependencyFactory */
    private $clazzFactory;

    /**
     * @param DependencyFactory $clazzFactory
     */
    public function __construct(DependencyFactory $clazzFactory)
    {
        $this->clazzFactory = $clazzFactory;

        $this->dependencies = new DependencyPairCollection();
        $this->tempDependencies = new DependencyPairCollection();

        $this->temporaryClass = $clazzFactory->createClazzFromStringArray(['temporary class']);
    }

    /**
     * This is called before any actual work is being done. The order in which
     * the file will be traversed is not always as expected. We therefore
     * might encounter a dependency before we actually know which class we are
     * in. To get around this issue we will set the current node to temp
     * and will update it later when we are done with the class.
     *
     * @param Node[] $nodes
     *
     * @return null|\PhpParser\Node[]|void
     */
    public function beforeTraverse(array $nodes)
    {
        $this->currentClass = $this->temporaryClass;
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLikeNode) {
            $this->setCurrentClass($node);

            if ($this->isSubclass($node)) {
                $this->addParentDependency($node);
            }

            if ($node instanceof ClassNode) {
                $this->addImplementedInterfaceDependency($node);
            }
        }

        if ($node instanceof NewNode
            && $node->class instanceof FullyQualifiedNameNode) {
            $this->addInstantiationDependency($node);
        } elseif ($node instanceof ClassMethodNode) {
            $this->addInjectedDependencies($node);
        } elseif ($node instanceof UseNode) {
            $this->addUseDependency($node);
        } elseif ($node instanceof MethodCallNode
            && $node->var instanceof StaticCallNode
            && $node->var->class instanceof NameNode) {
            $this->addStaticDependency($node);
        }
    }

    /**
     * As described in beforeTraverse we are going to update the class we are
     * currently parsing for all dependencies. If we are not in class context
     * we won't add the dependencies.
     *
     * @param Node $node
     *
     * @return false|null|Node|\PhpParser\Node[]|void
     */
    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassLikeNode) {
            // not in class context
            if ($this->currentClass->equals($this->temporaryClass)) {
                $this->tempDependencies = new DependencyPairCollection();
            }

            // by now the class should have been parsed so replace the
            // temporary class with the parsed class name
            $this->tempDependencies->each(function (DependencyPair $dependency) {
                $this->dependencies = $this->dependencies->add(new DependencyPair(
                    $this->currentClass,
                    $dependency->to()
                ));
            });
            $this->tempDependencies = new DependencyPairCollection();
        }
    }

    /**
     * @return DependencyPairCollection
     */
    public function dependencies() : DependencyPairCollection
    {
        return $this->dependencies;
    }

    /**
     * @param ClassLikeNode $node
     */
    private function setCurrentClass(ClassLikeNode $node)
    {
        if ($node instanceof InterfaceNode) {
            $this->currentClass = $this->clazzFactory->createInterfazeFromStringArray($node->namespacedName->parts);
        } else {
            $this->currentClass = $node->isAbstract()
                ? $this->clazzFactory->createAbstractClazzFromStringArray($node->namespacedName->parts)
                : $this->clazzFactory->createClazzFromStringArray($node->namespacedName->parts);
        }
    }

    /**
     * @param ClassLikeNode $node
     */
    private function addParentDependency(ClassLikeNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
            $this->currentClass,
            $this->clazzFactory->createClazzFromStringArray($node->extends->parts)
        ));
    }

    /**
     * @param ClassNode $node
     */
    private function addImplementedInterfaceDependency(ClassNode $node)
    {
        foreach ($node->implements as $interfaceNode) {
            $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
                $this->currentClass,
                $this->clazzFactory->createClazzFromStringArray($interfaceNode->parts)
            ));
        }
    }

    /**
     * @param NewNode $node
     */
    private function addInstantiationDependency(NewNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
            $this->currentClass,
            $this->clazzFactory->createClazzFromStringArray($node->class->parts)
        ));
    }

    /**
     * @param ClassMethodNode $node
     */
    private function addInjectedDependencies(ClassMethodNode $node)
    {
        foreach ($node->params as $param) {
            /* @var \PhpParser\Node\Param */
            if (isset($param->type, $param->type->parts)) {
                $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
                    $this->currentClass,
                    $this->clazzFactory->createClazzFromStringArray($param->type->parts)
                ));
            }
        }
    }

    /**
     * @param UseNode $node
     */
    private function addUseDependency(UseNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
            $this->currentClass,
            $this->clazzFactory->createClazzFromStringArray($node->uses[0]->name->parts)
        ));
    }

    /**
     * @param MethodCallNode $node
     */
    private function addStaticDependency(MethodCallNode $node)
    {
        $this->tempDependencies = $this->tempDependencies->add(new DependencyPair(
            $this->currentClass,
            $this->clazzFactory->createClazzFromStringArray($node->var->class->parts)
        ));
    }

    /**
     * @param ClassLikeNode $node
     *
     * @return bool
     */
    private function isSubclass(ClassLikeNode $node)
    {
        return !empty($node->extends);
    }
}
